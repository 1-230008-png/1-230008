#!/usr/bin/env python3
import argparse
import json
import math
import random
import sqlite3
import sys
from datetime import date

CN = ["Low", "Medium", "High"]
FEAT_NAMES = [
    "Prior violation count",
    "Violation type",
    "Recency",
    "Year level",
    "Course/department",
    "Violation interval",
    "Sanction compliance",
]


def get_db_path(path: str) -> str:
    return str(path)


def fv(conn: sqlite3.Connection):
    departments = [
        row[0]
        for row in conn.execute("SELECT DISTINCT department FROM students ORDER BY 1")
    ]
    return [
        ["0", "1", "2–3", "4+"],
        ["No prior violation", "Minor Only", "Mixed", "Major Only"],
        ["No prior violation", "≤30 days", "31–90", "91–180", ">180"],
        ["1st", "2nd", "3rd", "4th"],
        departments,
        ["Not Applicable", "<30 days", "30–90", ">90 days"],
        ["Full", "Partial", "Non-compliance", "No sanction"],
    ]


def features(student, violations, idx):
    n = len(violations)
    count_bucket = "0" if n < 1 else "1" if n == 1 else "2–3" if n < 4 else "4+"
    if not n:
        violation_type = "No prior violation"
        recency = "No prior violation"
        interval = "Not Applicable"
        compliance = "No sanction"
    else:
        major_count = sum(1 for v in violations if v["sev"] == "Major")
        violation_type = "Minor Only" if major_count == 0 else "Major Only" if major_count == n else "Mixed"
        days_since = (date.fromisoformat(idx) - date.fromisoformat(violations[-1]["d"]))
        days_delta = days_since.days
        recency = "≤30 days" if days_delta <= 30 else "31–90" if days_delta <= 90 else "91–180" if days_delta <= 180 else ">180"
        interval = "Not Applicable"
        if n >= 2:
            gap = (date.fromisoformat(violations[-1]["d"]) - date.fromisoformat(violations[0]["d"]))
            interval_value = gap.days / (n - 1)
            interval = "<30 days" if interval_value < 30 else "30–90" if interval_value <= 90 else ">90 days"
        compliance = violations[-1]["comp"]
    return [
        count_bucket,
        violation_type,
        recency,
        student["year_level"],
        student["department"],
        interval,
        compliance if n else "No sanction",
    ]


def load_rows(conn: sqlite3.Connection):
    rows = []
    for row in conn.execute(
        "SELECT f_count, f_type, f_recency, f_year, f_dept, f_interval, f_comp, risk_label FROM student_academic_year WHERE risk_label IS NOT NULL"
    ):
        rows.append(
            {
                "x": [row[0], row[1], row[2], row[3], row[4], row[5], row[6]],
                "y": CN.index(row[7]),
            }
        )
    return rows


def nb_train(rows, feature_values):
    cc = [0, 0, 0]
    cnt = [[{} for _ in range(3)] for _ in range(len(feature_values))]
    for data in rows:
        cc[data["y"]] += 1
        for i, value in enumerate(data["x"]):
            if i < len(cnt) and data["y"] < len(cnt[i]):
                bucket = cnt[i][data["y"]]
                bucket[value] = bucket.get(value, 0) + 1
    return {"cc": cc, "cnt": cnt, "n": len(rows), "FV": feature_values}


def lik(model, i, value, cls):
    denominator = model["cc"][cls] + len(model["FV"][i])
    return math.log((model["cnt"][i][cls].get(value, 0) + 1) / denominator)


def nb_post(model, x):
    probs = []
    for cls in range(3):
        score = math.log(model["cc"][cls] / model["n"]) if model["cc"][cls] > 0 else -1e9
        for i, value in enumerate(x):
            score += lik(model, i, value, cls)
        probs.append(score)
    mx = max(probs)
    exps = [math.exp(p - mx) for p in probs]
    total = sum(exps)
    if total <= 0:
        return [1 / 3, 1 / 3, 1 / 3]
    return [v / total for v in exps]


def nb_cv(rows, feature_values):
    rng = random.Random(2026)
    by_class = [[], [], []]
    for idx, row in enumerate(rows):
        by_class[row["y"]].append(idx)
    for bucket in by_class:
        rng.shuffle(bucket)
    folds = [-1] * len(rows)
    for cls_bucket in by_class:
        for j, idx in enumerate(cls_bucket):
            folds[idx] = j % 5
    confusion = [[0, 0, 0] for _ in range(3)]
    for k in range(5):
        train_rows = [row for i, row in enumerate(rows) if folds[i] != k]
        model = nb_train(train_rows, feature_values)
        for i, row in enumerate(rows):
            if folds[i] == k:
                probs = nb_post(model, row["x"])
                predicted = max(range(3), key=probs.__getitem__)
                confusion[row["y"]][predicted] += 1
    return confusion


def nb_met(matrix):
    n = 0
    ok = 0
    P = 0.0
    R = 0.0
    F = 0.0
    for cls in range(3):
        tp = matrix[cls][cls]
        col = sum(matrix[r][cls] for r in range(3))
        row = sum(matrix[cls])
        n += row
        ok += tp
        precision = tp / col if col else 0.0
        recall = tp / row if row else 0.0
        P += precision / 3
        R += recall / 3
        F += ((2 * precision * recall / (precision + recall)) if (precision + recall) else 0.0) / 3
    return {"ac": ok / n, "P": P, "R": R, "F": F}


def nb_base(rows):
    counts = [0, 0, 0]
    for row in rows:
        counts[row["y"]] += 1
    majority = max(range(3), key=counts.__getitem__)
    matrix = [[0, 0, 0] for _ in range(3)]
    for row in rows:
        matrix[row["y"]][majority] += 1
    return nb_met(matrix)


def predictions(conn: sqlite3.Connection, idx=None):
    feature_values = fv(conn)
    model = nb_train(load_rows(conn), feature_values)
    selected_idx = idx or date.today().isoformat()
    violations_by_student = {}
    for row in conn.execute(
        "SELECT v.study_id, v.vdate as d, v.severity as sev, s.compliance as comp FROM violations v LEFT JOIN sanctions s ON s.violation_id = v.violation_id ORDER BY v.vdate, v.violation_id"
    ):
        if row["d"] <= selected_idx:
            violations_by_student.setdefault(row["study_id"], []).append({"d": row["d"], "sev": row["sev"], "comp": row["comp"]})
    outputs = {}
    for student in conn.execute("SELECT * FROM students ORDER BY study_id"):
        value = dict(student)
        x = features(value, violations_by_student.get(value["study_id"], []), selected_idx)
        probs = nb_post(model, x)
        outputs[value["study_id"]] = {
            "s": value,
            "x": x,
            "p": probs,
            "c": max(range(3), key=probs.__getitem__),
        }
    return {"rows": outputs, "m": model}


def ensure_model(conn: sqlite3.Connection):
    if conn.execute("SELECT COUNT(*) FROM model_versions").fetchone()[0]:
        return {"ok": True, "skip": True}
    rows = load_rows(conn)
    if not rows:
        return {"ok": True, "skip": True}
    feature_values = fv(conn)
    matrix = nb_cv(rows, feature_values)
    metrics = nb_met(matrix)
    base = nb_base(rows)
    sql = "INSERT INTO model_versions(n_records,accuracy,macro_p,macro_r,macro_f1,base_acc,base_f1,matrix) VALUES(?,?,?,?,?,?,?,?)"
    conn.execute(
        sql,
        [
            len(rows),
            metrics["ac"],
            metrics["P"],
            metrics["R"],
            metrics["F"],
            base["ac"],
            base["F"],
            json.dumps(matrix),
        ],
    )
    conn.commit()
    return {"ok": True, "skip": False, "matrix": matrix, "metrics": metrics, "base": base}


def main():
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")
    parser = argparse.ArgumentParser(description="ASIATECH Python prediction engine")
    parser.add_argument("--db", required=True)
    parser.add_argument("--action", choices=["predict", "ensure-model"], required=True)
    parser.add_argument("--index-date", default=None)
    args = parser.parse_args()

    conn = sqlite3.connect(args.db)
    conn.row_factory = sqlite3.Row
    try:
        if args.action == "predict":
            payload = predictions(conn, args.index_date)
            sys.stdout.write(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
        elif args.action == "ensure-model":
            payload = ensure_model(conn)
            sys.stdout.write(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    finally:
        conn.close()


if __name__ == "__main__":
    main()
