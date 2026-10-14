<?php
// Prediction logic is now powered by a Python engine while preserving the existing PHP call surface.
const CN=['Low','Medium','High'];
function python_binary(){foreach(['py','python','python3'] as $candidate){$cmd=$candidate.' --version 2>&1';$proc=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))continue;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);$code=proc_close($proc);if($code===0)return $candidate;}
 return null;}
function python_prediction_engine($action,$idx=null){$bin=python_binary();if($bin===null)return null;$cmd=escapeshellarg($bin).' '.escapeshellarg(__DIR__.'/prediction_engine.py').' --db '.escapeshellarg(DB_FILE).' --action '.escapeshellarg($action);
 if($idx!==null){$cmd.=' --index-date '.escapeshellarg($idx);}
 $descs=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
 $proc=proc_open($cmd,$descs,$pipes);
 if(!is_resource($proc))return null;
 if(isset($pipes[0]))fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);$code=proc_close($proc);
 if($code!==0||trim($out)==='')return null;
 $data=json_decode($out,true);return is_array($data)?$data:null;}
function FV(){$d=db()->query('SELECT DISTINCT department FROM students ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN);
 return [['0','1','2–3','4+'],['No prior violation','Minor Only','Mixed','Major Only'],['No prior violation','≤30 days','31–90','91–180','>180'],['1st','2nd','3rd','4th'],$d,['Not Applicable','<30 days','30–90','>90 days'],['Full','Partial','Non-compliance','No sanction']];}
function features($s,$vs,$idx){$n=count($vs);$cnt=$n<1?'0':($n==1?'1':($n<4?'2–3':'4+'));
 if(!$n){$ty=$rc='No prior violation';}else{$mj=count(array_filter($vs,fn($v)=>$v['sev']=='Major'));$ty=$mj==0?'Minor Only':($mj==$n?'Major Only':'Mixed');
  $d=(strtotime($idx)-strtotime($vs[$n-1]['d']))/86400;$rc=$d<=30?'≤30 days':($d<=90?'31–90':($d<=180?'91–180':'>180'));}
 $iv='Not Applicable';if($n>=2){$g=(strtotime($vs[$n-1]['d'])-strtotime($vs[0]['d']))/86400/($n-1);$iv=$g<30?'<30 days':($g<=90?'30–90':'>90 days');}
 return [$cnt,$ty,$rc,$s['year_level'],$s['department'],$iv,$n?$vs[$n-1]['comp']:'No sanction'];}
function nb_train($rows,$FV){$cc=[0,0,0];$cnt=[];foreach($FV as $i=>$vs)for($c=0;$c<3;$c++)foreach($vs as $v)$cnt[$i][$c][$v]=0;
 foreach($rows as $r){$cc[$r['y']]++;foreach($r['x'] as $i=>$v)if(isset($cnt[$i][$r['y']][$v]))$cnt[$i][$r['y']][$v]++;}
 return ['cc'=>$cc,'cnt'=>$cnt,'n'=>count($rows),'FV'=>$FV];}
function lik($m,$i,$v,$c){return log((($m['cnt'][$i][$c][$v]??0)+1)/($m['cc'][$c]+count($m['FV'][$i])));}
function nb_post($m,$x){$lp=[];for($c=0;$c<3;$c++){$s=$m['cc'][$c]>0?log($m['cc'][$c]/$m['n']):-1e9;foreach($x as $i=>$v)$s+=lik($m,$i,$v,$c);$lp[]=$s;}
 $mx=max($lp);$e=array_map(fn($l)=>exp($l-$mx),$lp);$t=array_sum($e);return array_map(fn($v)=>$v/$t,$e);}
function nb_cv($rows,$FV){mt_srand(2026);$f=[];$by=[[],[],[]];foreach($rows as $i=>$r)$by[$r['y']][]=$i;
 foreach($by as $ix){shuffle($ix);foreach($ix as $j=>$i)$f[$i]=$j%5;}
 $M=array_fill(0,3,[0,0,0]);
 for($k=0;$k<5;$k++){$tr=[];foreach($rows as $i=>$r)if($f[$i]!=$k)$tr[]=$r;$m=nb_train($tr,$FV);
  foreach($rows as $i=>$r)if($f[$i]==$k){$p=nb_post($m,$r['x']);$M[$r['y']][array_search(max($p),$p)]++;}}
 return $M;}
function nb_met($M){$n=0;$ok=0;$P=$R=$F=0;for($c=0;$c<3;$c++){$tp=$M[$c][$c];$col=$M[0][$c]+$M[1][$c]+$M[2][$c];$row=array_sum($M[$c]);$n+=$row;$ok+=$tp;
 $p=$col?$tp/$col:0;$r=$row?$tp/$row:0;$P+=$p/3;$R+=$r/3;$F+=($p+$r?2*$p*$r/($p+$r):0)/3;}return ['ac'=>$ok/$n,'P'=>$P,'R'=>$R,'F'=>$F];}
function nb_base($rows){$d=[0,0,0];foreach($rows as $r)$d[$r['y']]++;$m=array_search(max($d),$d);$M=array_fill(0,3,[0,0,0]);foreach($rows as $r)$M[$r['y']][$m]++;return nb_met($M);}
function load_rows(){$o=[];foreach(db()->query('SELECT f_count,f_type,f_recency,f_year,f_dept,f_interval,f_comp,risk_label FROM student_academic_year WHERE risk_label IS NOT NULL') as $r){
 $o[]=['x'=>[$r['f_count'],$r['f_type'],$r['f_recency'],$r['f_year'],$r['f_dept'],$r['f_interval'],$r['f_comp']],'y'=>array_search($r['risk_label'],CN)];}return $o;}
function predictions(){$py=python_prediction_engine('predict');if(is_array($py)&&isset($py['rows']))return $py;
 $FV=FV();$m=nb_train(load_rows(),$FV);$idx=INDEX_DATE??date('Y-m-d');$V=[];
 foreach(db()->query('SELECT v.study_id,v.vdate d,v.severity sev,s.compliance comp FROM violations v LEFT JOIN sanctions s ON s.violation_id=v.violation_id ORDER BY v.vdate,v.violation_id') as $r)if($r['d']<=$idx)$V[$r['study_id']][]=$r;
 $out=[];foreach(db()->query('SELECT * FROM students ORDER BY study_id') as $s){$x=features($s,$V[$s['study_id']]??[],$idx);$p=nb_post($m,$x);$out[$s['study_id']]=['s'=>$s,'x'=>$x,'p'=>$p,'c'=>array_search(max($p),$p)];}
 return ['rows'=>$out,'m'=>$m];}
function ensure_model($force=false){if(!$force){$py=python_prediction_engine('ensure-model');if(is_array($py))return;}$rows=load_rows();if(!$rows)return;$M=nb_cv($rows,FV());$t=nb_met($M);$b=nb_base($rows);
 db()->prepare('INSERT INTO model_versions(n_records,accuracy,macro_p,macro_r,macro_f1,base_acc,base_f1,matrix) VALUES(?,?,?,?,?,?,?,?)')->execute([count($rows),$t['ac'],$t['P'],$t['R'],$t['F'],$b['ac'],$b['F'],json_encode($M)]);}
