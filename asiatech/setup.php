<?php
// Optional: rebuilds the SQLite database from data/*.csv (use after replacing the CSVs with real data). Localhost only.
error_reporting(E_ALL);ini_set('display_errors',1);require 'config.php';require 'nb.php';
if(!in_array($_SERVER['REMOTE_ADDR'],['127.0.0.1','::1'])){http_response_code(403);exit('Localhost only');}
@unlink(DB_FILE);$pdo=connect();$pdo->exec(file_get_contents(__DIR__.'/schema.sql'));
function rd($f){$h=fopen($f,'r');$hd=fgetcsv($h,0,',','"','\\');$hd[0]=ltrim($hd[0],"\xEF\xBB\xBF");while(($r=fgetcsv($h,0,',','"','\\'))!==false)if(count($r)==count($hd))yield array_combine($hd,$r);}
$pdo->beginTransaction();$st=[];
foreach(rd(__DIR__.'/data/model_dataset.csv') as $r){$sid=student_account_id($r['study_id']);$hasRealName=isset($r['full_name'])&&trim($r['full_name'])!=='';$demoData=!$hasRealName;$department=(is_demo_department($r['department'])||student_demo_is_fourth_year($sid))&&$demoData?(student_demo_program($sid)??$r['department']):$r['department'];$yearLevel=student_demo_is_fourth_year($sid)&&$demoData?'4th':$r['year_level'];$fullName=$hasRealName?trim($r['full_name']):student_demo_name($sid);$st[$sid]=[$department,$yearLevel,$fullName,$demoData];
 $pdo->prepare('INSERT INTO student_academic_year VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$r['record_id'],$sid,$r['academic_year'],$r['index_date'],$r['prior_violation_count'],$r['violation_type'],$r['recency'],$r['year_level'],$department,$r['violation_interval'],$r['sanction_compliance'],$r['followup_violations']===''?null:$r['followup_violations'],$r['risk_label']===''?null:$r['risk_label']]);}
foreach($st as $id=>$s)$pdo->prepare('INSERT INTO students(study_id,full_name,department,year_level) VALUES(?,?,?,?)')->execute([$id,$s[2],$s[0],$s[1]]);
foreach($st as $id=>$s)if($s[3]&&student_demo_is_fourth_year($id))$pdo->prepare('UPDATE student_academic_year SET f_year=?,f_dept=? WHERE study_id=? AND index_date=(SELECT MAX(latest.index_date) FROM student_academic_year latest WHERE latest.study_id=?)')->execute([$s[1],$s[0],$id,$id]);
foreach(rd(__DIR__.'/data/violations.csv') as $r){$pdo->prepare('INSERT INTO violations(violation_id,study_id,vdate,academic_year,category,severity,recorded_by) VALUES(?,?,?,?,?,?,?)')->execute([$r['violation_id'],student_account_id($r['study_id']),$r['date'],$r['academic_year'],$r['category'],$r['severity'],$r['recorded_by']]);
 $pdo->prepare('INSERT INTO sanctions(violation_id,compliance) VALUES(?,?)')->execute([$r['violation_id'],$r['sanction_compliance']]);}
$pdo->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute(['demo-it-fourth-year-cohort']);$pdo->commit();seed_users($pdo);$pdo=null;ensure_model();
echo '<h2>Database rebuilt ✔</h2><p>'.count($st).' students. <a href="index.php">Go to login</a>.</p>';
