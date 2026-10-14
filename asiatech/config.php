<?php
session_start();
// Prediction index date: null = today. Sample data ends 2026-05-31, so use a date right after it.
const INDEX_DATE='2026-06-01';
const CATS=['Minor'=>['Tardiness','Improper uniform','Disruptive behavior','Use of phone in class','Littering'],'Major'=>['Fighting','Cheating','Vandalism','Bullying','Theft']];
const COMPL=['Full','Partial','Non-compliance','No sanction'];
const COLLEGE_PROGRAMS=['Associate in Hospitality Management','Associate in Information Technology','BS in Accountancy','BS in Business Administration (Major in Marketing Management)','BS in Business Administration (Major in Human Resource Management)','BS in Hospitality Management','BS in Tourism Management','BS in Criminology','BS in Computer Engineering','BS in Computer Science','BS in Information Technology','BS in Information System','Bachelor of Secondary Education'];
const SHS_STRANDS=['Senior High - ABM','Senior High - STEM','Senior High - HUMSS','Senior High - GAS','Senior High - ICT','Senior High - HE'];
if(!extension_loaded('pdo_sqlite'))exit('Enable "extension=pdo_sqlite" in C:\\xampp\\php\\php.ini (remove the semicolon) then restart Apache.');
const DB_FILE=__DIR__.'/data/asiatech.sqlite';
function connect(){$p=new PDO('sqlite:'.DB_FILE,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$p->exec('PRAGMA foreign_keys=ON');return $p;}
function student_account_id($id){if(preg_match('/^S-(\d+)$/D',(string)$id,$m))return '1-23'.str_pad($m[1],4,'0',STR_PAD_LEFT);return (string)$id;}
function student_demo_program($id){if(preg_match('/^(?:S-|1-23)(\d+)$/D',(string)$id,$m)){if((int)$m[1]<=10)return 'BS in Information Technology';$programs=[...COLLEGE_PROGRAMS,...SHS_STRANDS];return $programs[((int)$m[1]-1)%count($programs)];}return null;}
function student_demo_is_fourth_year($id){return preg_match('/^(?:S-|1-23)(\d+)$/D',(string)$id,$m)===1&&(int)$m[1]<=10;}
function student_demo_name($id){static $provided=['Brent Domingo','Nikko Fadul','Patrick Claud','Jastine James M. Gungon','Ronnie Carianga','Kristan Rain Amante','Dave Winsley Garcia','Kurt Hermano','Kyle Bocalbos','Jommel Briones'];
 static $first=['Aaron','Adrian','Alexis','Althea','Andrea','Angela','Angelo','Anne','Arielle','Ashley','Bea','Carlo','Carmela','Catherine','Christian','Clarisse','Daniel','Daphne','Daryl','Diana','Earl','Elaine','Elijah','Erika','Francis','Gabriel','Hannah','Ian','Isabel','Jared','Jasmine','Jerome','Joanna','John','Joshua','Joy','Julia','Justine','Katherine','Lance','Leah','Louise','Maureen','Miguel','Nicole','Paolo','Rafael','Rica','Samantha','Trisha'];
 static $last=['Abad','Aguilar','Alonzo','Aquino','Bautista','Castillo','Cruz','Dela Cruz','Delos Reyes','Diaz','Dominguez','Evangelista','Fernandez','Flores','Francisco','Garcia','Gonzales','Gutierrez','Hernandez','Lim','Lopez','Manalo','Mendoza','Navarro','Ocampo','Pascual','Reyes','Rivera','Santos','Soriano','Tan','Torres','Valdez','Villanueva'];
 if(preg_match('/^(?:S-|1-23)(\d+)$/D',(string)$id,$m)){$number=(int)$m[1];if($number>0&&$number<=count($provided))return $provided[$number-1];$offset=max(0,$number-count($provided)-1);return $first[$offset%count($first)].' '.$last[(intdiv($offset,count($first)))%count($last)];}
 return 'Sample Student '.$id;}
function is_demo_department($department){return in_array($department,['Dept A','Dept B','Dept C','Dept D'],true);}
function migrate_student_accounts($p){$columns=$p->query('PRAGMA table_info(students)')->fetchAll(PDO::FETCH_COLUMN,1);if(!in_array('full_name',$columns,true))$p->exec('ALTER TABLE students ADD COLUMN full_name TEXT');$p->exec('CREATE TABLE IF NOT EXISTS app_migrations(migration_key TEXT PRIMARY KEY,applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
 $cohortMigration='demo-it-fourth-year-cohort';$cohortCheck=$p->prepare('SELECT 1 FROM app_migrations WHERE migration_key=?');$cohortCheck->execute([$cohortMigration]);$cohortApplied=(bool)$cohortCheck->fetchColumn();
 $students=$p->query('SELECT s.study_id,s.full_name,s.department,s.year_level,EXISTS(SELECT 1 FROM student_academic_year a WHERE a.study_id=s.study_id AND a.index_date=(SELECT MAX(latest.index_date) FROM student_academic_year latest WHERE latest.study_id=s.study_id) AND (a.f_dept IS NOT s.department OR a.f_year IS NOT s.year_level)) AS academic_mismatch FROM students s ORDER BY s.study_id')->fetchAll();$updates=[];$modelChanged=false;
 foreach($students as $student){$id=student_account_id($student['study_id']);$department=$student['department'];$applyCohort=!$cohortApplied&&student_demo_is_fourth_year($id);if(is_demo_department($department)||$applyCohort)$department=student_demo_program($id)??$department;$yearLevel=$applyCohort?'4th':$student['year_level'];
  $name=trim((string)$student['full_name'])!==''?$student['full_name']:student_demo_name($id);
  if($id!==$student['study_id']||$department!==$student['department']||$yearLevel!==$student['year_level']||(int)$student['academic_mismatch'])$modelChanged=true;
  if($id!==$student['study_id']||$department!==$student['department']||$yearLevel!==$student['year_level']||(int)$student['academic_mismatch']||$name!==$student['full_name'])$updates[]=[$student['study_id'],$id,$department,$yearLevel,$name];}
 if(!$updates){if(!$cohortApplied)$p->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$cohortMigration]);return false;}
 $p->exec('PRAGMA defer_foreign_keys=ON');$p->beginTransaction();
 try{foreach($updates as [$old,$id,$department,$yearLevel,$name]){
   $check=$p->prepare('SELECT 1 FROM students WHERE study_id=? AND study_id<>?');$check->execute([$id,$old]);if($check->fetchColumn())throw new RuntimeException('Student account migration conflicts with an existing account number.');
   $p->prepare('UPDATE violations SET study_id=? WHERE study_id=?')->execute([$id,$old]);
   $p->prepare('UPDATE student_academic_year SET study_id=?,f_dept=? WHERE study_id=?')->execute([$id,$department,$old]);
   if(student_demo_is_fourth_year($id))$p->prepare('UPDATE student_academic_year SET f_year=?,f_dept=? WHERE study_id=? AND index_date=(SELECT MAX(latest.index_date) FROM student_academic_year latest WHERE latest.study_id=?)')->execute([$yearLevel,$department,$id,$id]);
   $p->prepare('UPDATE risk_classifications SET study_id=? WHERE study_id=?')->execute([$id,$old]);
   $p->prepare('UPDATE students SET study_id=?,full_name=?,department=?,year_level=? WHERE study_id=?')->execute([$id,$name,$department,$yearLevel,$old]);}
  if(!$cohortApplied)$p->prepare('INSERT INTO app_migrations(migration_key) VALUES(?)')->execute([$cohortMigration]);
  $p->commit();return $modelChanged;
 }catch(Throwable $x){if($p->inTransaction())$p->rollBack();throw $x;}}
function seed_users($p){if($p->query('SELECT COUNT(*) FROM users')->fetchColumn())return;$h=defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT;
 foreach([['admin','admin123','Administrator','Admin'],['osa','osa123','OSA','OSA Staff'],['teacher','teacher123','Teacher','Teacher']] as $u)$p->prepare('INSERT INTO users(username,password_hash,role,full_name) VALUES(?,?,?,?)')->execute([$u[0],password_hash($u[1],$h),$u[2],$u[3]]);}
function db(){static $p;if(!$p){try{$p=connect();$migrated=migrate_student_accounts($p);seed_users($p);if($migrated)ensure_model(true);}catch(Throwable $x){exit('Database not ready: '.e($x->getMessage()).' — open setup.php once.');}}return $p;}
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function user(){return $_SESSION['u']??null;}
function need($roles){$u=user();if(!$u||!in_array($u['role'],$roles,true)){http_response_code(403);exit('Access denied');}}
function csrf(){return $_SESSION['t']??($_SESSION['t']=bin2hex(random_bytes(16)));}
function chk(){if(!hash_equals(csrf(),$_POST['t']??'')){http_response_code(400);exit('Invalid token');}}
function audit($a){$u=user();db()->prepare('INSERT INTO audit_logs(user_id,action) VALUES(?,?)')->execute([$u['id']??null,$a]);}
