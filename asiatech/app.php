<?php require 'config.php';require 'nb.php';
$u=user();if(!$u){header('Location: index.php');exit;}$role=$u['role'];db();ensure_model();
$NAV=['Administrator'=>['dash','pred','rec','audit'],'OSA'=>['dash','pred','rec'],'Teacher'=>['rec']];
$NL=['dash'=>'Dashboard','pred'=>'Risk Predictions','rec'=>'Violation Records','audit'=>'Audit Log'];
$v=$_GET['v']??$NAV[$role][0];if(!in_array($v,$NAV[$role],true))$v=$NAV[$role][0];
if($_SERVER['REQUEST_METHOD']==='POST'){chk();$a=$_POST['a']??'';
 if($a==='review'){need(['Administrator']);$P=predictions();$r=$P['rows'][$_POST['id']??'']??null;$st=$_POST['st']??'';
  if($r&&in_array($st,['Accepted','Deferred','Rejected'],true)){$mv=db()->query('SELECT MAX(version) FROM model_versions')->fetchColumn();
   db()->prepare('INSERT INTO risk_classifications(study_id,model_version,risk_class,p_low,p_med,p_high,review_status,reviewed_by,reviewed_at) VALUES(?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(study_id) DO UPDATE SET model_version=excluded.model_version,risk_class=excluded.risk_class,p_low=excluded.p_low,p_med=excluded.p_med,p_high=excluded.p_high,review_status=excluded.review_status,reviewed_by=excluded.reviewed_by,reviewed_at=CURRENT_TIMESTAMP')
    ->execute([$r['s']['study_id'],$mv,CN[$r['c']],$r['p'][0],$r['p'][1],$r['p'][2],$st,$u['name']]);audit("Review $st: ".$r['s']['study_id']);}
  header('Location: app.php?v=pred&id='.urlencode($_POST['id']??''));exit;}
 if($a==='add'){need(['Administrator','Teacher']);$sid=$_POST['sid']??'';$d=$_POST['d']??'';$sev=$_POST['sev']??'';$cat=$_POST['cat']??'';$cp=$_POST['cp']??'';
  $other=$cat==='Others (Specify)'?trim($_POST['other_cat']??''):'';$custom=$cat==='Others (Specify)';$cat=$custom?$other:$cat;
  $validCat=$custom?($cat!==''&&mb_strlen($cat)<=120):in_array($cat,CATS[$sev]??[],true);
  $ok=$sid&&$d&&$cat&&in_array($sev,['Minor','Major'],true)&&$validCat&&in_array($cp,COMPL,true)&&strtotime($d)&&$d<=date('Y-m-d');
  $q=db()->prepare('SELECT department FROM students WHERE study_id=?');$q->execute([$sid]);if(!$q->fetch())$ok=false;
  if(!$ok){$_SESSION['msg']=['err','Please complete all required fields correctly.'];}else{$pdo=db();$pdo->beginTransaction();
   $n=$pdo->query('SELECT COALESCE(MAX(CAST(SUBSTR(violation_id,3) AS INTEGER)),0)+1 FROM violations')->fetchColumn();$id=sprintf('V-%05d',$n);
   $y=(int)date('Y',strtotime($d));$m=(int)date('n',strtotime($d));$ay=$m>=6?"$y-".($y+1):($y-1)."-$y";
   $pdo->prepare('INSERT INTO violations(violation_id,study_id,vdate,academic_year,category,severity,recorded_by) VALUES(?,?,?,?,?,?,?)')->execute([$id,$sid,$d,$ay,$cat,$sev,$u['name']]);
   $pdo->prepare('INSERT INTO sanctions(violation_id,compliance) VALUES(?,?)')->execute([$id,$cp]);$pdo->commit();audit("Added $sev violation $id for $sid");$_SESSION['msg']=['ok','Record saved.'];}
  header('Location: app.php?v=rec');exit;}}
$msg=$_SESSION['msg']??null;unset($_SESSION['msg']);
$pct=fn($x)=>number_format($x*100,1).'%';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ASIATECH Risk Monitoring</title><link rel="stylesheet" href="style.css?v=<?=filemtime(__DIR__.'/style.css')?>"></head><body>
<header class="top"><img src="assets/logo.png" alt=""><b>ASIATECH Disciplinary Risk Monitoring</b><span class="sp"></span><span><?=e($u['name'])?> · <?=e($role)?></span><a class="ghost" href="logout.php">Log out</a></header>
<nav class="tabs"><?php foreach($NAV[$role] as $n)echo '<a class="'.($n===$v?'on':'').'" href="app.php?v='.$n.'">'.$NL[$n].'</a>';?></nav><main class="<?= $v==='dash'?'dashboard':'' ?>">
<?php
if($v==='dash'){$P=predictions();$cn=[0,0,0];foreach($P['rows'] as $r)$cn[$r['c']]++;$mv=db()->query('SELECT * FROM model_versions ORDER BY version DESC LIMIT 1')->fetch();$M=json_decode($mv['matrix'],true);
 $nv=db()->query('SELECT COUNT(*) FROM violations')->fetchColumn();
 echo '<div class="grid"><div class="card kpi"><b>'.count($P['rows']).'</b><span>Students monitored</span></div>';foreach($cn as $i=>$c)echo '<div class="card kpi"><b style="color:var(--'.['lo','md','hi'][$i].')">'.$c.'</b><span>'.CN[$i].' Risk</span></div>';
 echo '<div class="card kpi"><b>'.$nv.'</b><span>Violation records</span></div></div><div class="row2"><div class="card"><h3>Model v'.$mv['version'].' — 5-fold stratified CV ('.$mv['n_records'].' labeled records)</h3><table><tr><th></th><th>Naive Bayes</th><th>Majority baseline</th></tr>';
 echo '<tr><td>Accuracy</td><td>'.$pct($mv['accuracy']).'</td><td>'.$pct($mv['base_acc']).'</td></tr><tr><td>Macro-precision</td><td>'.$pct($mv['macro_p']).'</td><td>—</td></tr><tr><td>Macro-recall</td><td>'.$pct($mv['macro_r']).'</td><td>—</td></tr><tr><td>Macro-F1</td><td>'.$pct($mv['macro_f1']).'</td><td>'.$pct($mv['base_f1']).'</td></tr></table></div>';
 $matrixMax=max(1,max(array_map('max',$M)));
 echo '<div class="card"><h3>Confusion matrix (rows = actual)</h3><table class="matrix-3d"><tr><th></th>';foreach(CN as $c)echo "<th>$c</th>";echo '</tr>';foreach($M as $i=>$r){echo '<tr><th>'.CN[$i].'</th>';foreach($r as $j=>$value)echo '<td><span class="matrix-cell level'.$j.'"><i style="width:'.round($value/$matrixMax*100).'%"></i><b>'.(int)$value.'</b></span></td>';echo '</tr>';}
 echo '</table></div></div><div class="note">Classifications are decision-support only. No sanction is created automatically; every recommendation requires human review.</div>';}
if($v==='pred'){need(['Administrator','OSA']);$P=predictions();$flt=$_GET['f']??'All';$sel=$_GET['id']??'';$rv=[];foreach(db()->query('SELECT study_id,review_status,reviewed_by FROM risk_classifications') as $r)$rv[$r['study_id']]=$r;
 $REC=['Routine monitoring.','Suggested check-in / counseling review.','Priority OSA review.'];
 if($sel&&isset($P['rows'][$sel])){$r=$P['rows'][$sel];
  echo '<div class="card" style="border-color:var(--g5)"><h3>'.e($r['s']['full_name']).' · '.e($sel).' — <span class="badge b'.$r['c'].'">'.CN[$r['c']].'</span></h3><div>';
  foreach(CN as $i=>$c)echo '<div class="bar"><span style="width:62px">'.$c.'</span><i class="c'.$i.'" style="width:'.round($r['p'][$i]*160).'px"></i>'.$pct($r['p'][$i]).'</div>';
  echo '<p><b>Recommendation:</b> '.$REC[$r['c']].'</p><p>Review status: <b>'.(isset($rv[$sel])?e($rv[$sel]['review_status'].' by '.$rv[$sel]['reviewed_by']):'Pending').'</b></p>';
  if($role==='Administrator'){echo '<form method="post" style="display:flex;gap:6px"><input type="hidden" name="t" value="'.e(csrf()).'"><input type="hidden" name="a" value="review"><input type="hidden" name="id" value="'.e($sel).'">';
   foreach(['Accepted','Deferred','Rejected'] as $s)echo '<button class="'.($s==='Accepted'?'btn':'chip').'" name="st" value="'.$s.'">'.$s.'</button>';echo '</form>';}
  else echo '<span class="ro">View-only access. Review actions are done by authorized administrators.</span>';
  echo '</div></div>';}
 $L=array_filter($P['rows'],fn($r)=>$flt==='All'||CN[$r['c']]===$flt);uasort($L,fn($a,$b)=>[$b['c'],$b['p'][2]]<=>[$a['c'],$a['p'][2]]);$showAll=($_GET['all']??'')==='1';$visibleRows=array_slice($L,0,$showAll?count($L):5,true);
 echo '<div class="card"><h3>Predicted risk of repeat violation (next academic year)</h3><div class="note">Student names and course / strand assignments are sample data; verify them before operational use. Risk classifications are model-generated from recorded data, not manually assigned by name or course.</div><div style="margin-bottom:10px">';foreach(['All',...CN] as $f)echo '<a class="chip '.($flt===$f?'on':'').'" href="app.php?v=pred&f='.$f.'">'.$f.'</a>';
 echo '</div><div class="wrap"><table><tr><th>Student ID</th><th>Student</th><th>Year</th><th>Course / Strand</th><th>Prior</th><th>Risk</th><th>Probability</th><th>Review</th><th></th></tr>';
 foreach($visibleRows as $id=>$r){$probabilityLabel=implode(', ',array_map(fn($i,$p)=>CN[$i].' '.$pct($p),array_keys(CN),$r['p']));echo '<tr><td>'.e($id).'</td><td>'.e($r['s']['full_name']).'</td><td>'.e($r['s']['year_level']).'</td><td>'.e($r['s']['department']).'</td><td>'.e($r['x'][0]).'</td><td><span class="badge b'.$r['c'].'">'.CN[$r['c']].'</span></td><td><div class="stack probability-3d" role="img" tabindex="0" aria-label="'.e('Risk probabilities: '.$probabilityLabel).'">'.implode('',array_map(fn($i,$p)=>'<i class="prob-segment p'.$i.'" style="width:'.($p*100).'%" title="'.e(CN[$i].': '.$pct($p)).'"></i>',array_keys(CN),$r['p'])).'</div></td><td>'.(isset($rv[$id])?e($rv[$id]['review_status']):'Pending').'</td><td><a class="chip" href="app.php?v=pred&f='.e($flt).'&id='.urlencode($id).($showAll?'&all=1':'').'">View</a></td></tr>';}
 echo '</table></div>'.(count($L)>5?'<div style="text-align:center;margin-top:12px"><a class="btn" href="app.php?v=pred&f='.e($flt).($showAll?'':'&all=1').'">'.($showAll?'Show first 5':'See all').' ('.count($L).')</a></div>':'').'</div>';}
if($v==='rec'){$mine=$role==='Teacher';$admin=$role==='Administrator';$canFilter=$admin||$role==='OSA';$canReport=$role!=='OSA';
 if($canReport){
 echo '<div class="card"><h3>Report a violation</h3><form method="post" class="row2"><input type="hidden" name="t" value="'.e(csrf()).'"><input type="hidden" name="a" value="add"><label class="f">Student<select name="sid" required><option value="">Select…</option>';foreach(db()->query('SELECT study_id,full_name FROM students ORDER BY full_name,study_id') as $student)echo '<option value="'.e($student['study_id']).'">'.e($student['full_name'].' · '.$student['study_id']).'</option>';
 echo '</select></label><label class="f">Date<input type="date" name="d" max="'.date('Y-m-d').'" value="'.date('Y-m-d').'" required></label><label class="f">Severity<select name="sev" id="sev" onchange="cats()"><option>Minor</option><option>Major</option></select></label><label class="f">Category<select name="cat" id="cat" onchange="otherCategory()" required></select></label><label class="f" id="other-wrap" style="display:none">Specify category<input type="text" name="other_cat" id="other-cat" maxlength="120"></label>';
 echo '<label class="f">Sanction compliance<select name="cp">';foreach(COMPL as $c)echo "<option>$c</option>";echo '</select></label><div><button class="btn" style="margin-top:18px">Save record</button></div></form>';
 if($msg)echo '<div class="err '.($msg[0]==='ok'?'ok':'').'">'.e($msg[1]).'</div>';
 if($mine)echo '<div class="note">Teachers submit incident records only; risk labels are not shown to this role.</div>';
 echo '</div><script>const C='.json_encode(CATS).';function cats(){const s=document.getElementById("cat");s.innerHTML=C[document.getElementById("sev").value].map(c=>"<option>"+c+"</option>").join("")+"<option>Others (Specify)</option>";otherCategory()}function otherCategory(){const show=document.getElementById("cat").value==="Others (Specify)";document.getElementById("other-wrap").style.display=show?"block":"none";document.getElementById("other-cat").required=show}cats()</script>';}
 $filter=$_GET['filter']??'all';if(!in_array($filter,['all','date','last_week','last_month','month'],true))$filter='all';
 $filterDate=$_GET['filter_date']??'';$filterMonth=$_GET['filter_month']??'';$where=[];$params=[];
 if($mine){$where[]='v.recorded_by=?';$params[]=$u['name'];}
 elseif($canFilter){
  if($filter==='last_week'){$where[]='v.vdate BETWEEN ? AND ?';$params[]=date('Y-m-d',strtotime('-6 days'));$params[]=date('Y-m-d');}
  elseif($filter==='last_month'){$where[]='v.vdate BETWEEN ? AND ?';$params[]=date('Y-m-d',strtotime('-29 days'));$params[]=date('Y-m-d');}
  elseif($filter==='date'){$dateObj=DateTimeImmutable::createFromFormat('!Y-m-d',$filterDate);if($dateObj&&$dateObj->format('Y-m-d')===$filterDate){$where[]='v.vdate=?';$params[]=$filterDate;}else $filter='all';}
  elseif($filter==='month'&&preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$filterMonth)){$monthStart=DateTimeImmutable::createFromFormat('!Y-m-d',$filterMonth.'-01');$where[]='v.vdate>=? AND v.vdate<?';$params[]=$monthStart->format('Y-m-d');$params[]=$monthStart->modify('first day of next month')->format('Y-m-d');}
  elseif($filter==='month')$filter='all';
 }
 $from=' FROM violations v JOIN students st ON st.study_id=v.study_id LEFT JOIN sanctions s ON s.violation_id=v.violation_id';$whereSql=$where?' WHERE '.implode(' AND ',$where):'';$total=db()->prepare('SELECT COUNT(*)'.$from.$whereSql);$total->execute($params);$recordCount=(int)$total->fetchColumn();$showAll=($_GET['all']??'')==='1';
 $sql='SELECT v.*,s.compliance,st.full_name'.$from.$whereSql.' ORDER BY v.vdate DESC,v.violation_id DESC'.($showAll?'':' LIMIT 5');
 $q=db()->prepare($sql);$q->execute($params);
 echo '<div class="card"><h3>'.($mine?'My recent submissions':($canFilter?'Violation records':'Latest violation records')).'</h3>';
 if($canFilter){echo '<form method="get" action="app.php" class="filterbar"><input type="hidden" name="v" value="rec"><label class="f">Date filter<select name="filter" id="record-filter" onchange="updateViolationFilter()">';foreach(['all'=>'All dates','date'=>'Specific date','last_week'=>'Last week (7 days)','last_month'=>'Last month (30 days)','month'=>'Specific month'] as $key=>$label)echo '<option value="'.$key.'"'.($filter===$key?' selected':'').'>'.$label.'</option>';echo '</select></label><label class="f filter-date" id="filter-date-wrap" style="display:none">Date<input type="date" name="filter_date" id="filter-date" value="'.e($filterDate).'"></label><label class="f filter-month" id="filter-month-wrap" style="display:none">Month<input type="month" name="filter_month" id="filter-month" value="'.e($filterMonth).'"></label><button class="btn" type="submit">Apply filter</button></form>';}
 echo '<div class="wrap"><table><tr><th>Violation ID</th><th>Student ID</th><th>Student</th><th>Date</th><th>Category</th><th>Severity</th><th>Compliance</th><th>By</th></tr>';
 foreach($q as $r)echo '<tr><td>'.e($r['violation_id']).'</td><td>'.e($r['study_id']).'</td><td>'.e($r['full_name']).'</td><td>'.e($r['vdate']).'</td><td>'.e($r['category']).'</td><td>'.e($r['severity']).'</td><td>'.e($r['compliance']).'</td><td>'.e($r['recorded_by']).'</td></tr>';echo '</table></div>';
 $listQuery=['v'=>'rec','filter'=>$filter];if($filter==='date'&&$filterDate!=='')$listQuery['filter_date']=$filterDate;if($filter==='month'&&$filterMonth!=='')$listQuery['filter_month']=$filterMonth;if(!$showAll)$listQuery['all']='1';$toggleUrl='app.php?'.http_build_query($listQuery);
 if($recordCount>5)echo '<div style="text-align:center;margin-top:12px"><a class="btn" href="'.e($toggleUrl).'">'.($showAll?'Show first 5':'See all').' ('.$recordCount.')</a></div>';echo '</div>';
 if($canFilter)echo '<script>function updateViolationFilter(){const f=document.getElementById("record-filter").value,date=f==="date",month=f==="month";document.getElementById("filter-date-wrap").style.display=date?"block":"none";document.getElementById("filter-month-wrap").style.display=month?"block":"none";document.getElementById("filter-date").required=date;document.getElementById("filter-month").required=month}updateViolationFilter()</script>';}
if($v==='audit'){need(['Administrator']);$showAll=($_GET['all']??'')==='1';$auditCount=(int)db()->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();$auditSql='SELECT a.created_at,u.full_name,a.action FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.audit_id DESC'.($showAll?'':' LIMIT 5');echo '<div class="card"><h3>Audit log</h3><div class="wrap"><table><tr><th>Time</th><th>User</th><th>Action</th></tr>';
 foreach(db()->query($auditSql) as $r)echo '<tr><td>'.e($r['created_at']).'</td><td>'.e($r['full_name']).'</td><td>'.e($r['action']).'</td></tr>';echo '</table></div>';
 if($auditCount>5)echo '<div style="text-align:center;margin-top:12px"><a class="btn" href="app.php?v=audit'.($showAll?'':'&amp;all=1').'">'.($showAll?'Show first 5':'See all').' ('.$auditCount.')</a></div>';echo '</div>';}
?></main></body></html>
