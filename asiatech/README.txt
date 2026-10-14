ASIATECH Predictive Disciplinary Risk System (PHP + Python + SQLite, XAMPP)
NO MySQL needed - the database is a single file: data/asiatech.sqlite (already filled with the sample dataset).
Prediction scoring and model training are powered by the Python engine in prediction_engine.py, while the PHP app remains the user-facing interface.
Student account numbers use the 1-23NNNN format (for example, S-001 becomes 1-230001). Student names are sample data: the first ten use the names provided for the demo, and the remaining names are generated fictional examples. The first ten sample students are assigned to 4th year BS in Information Technology. Replace all sample names and course details with verified records before operational use. Risk classifications remain model-generated from student record data; they are not assigned based on names or courses.

1. Copy the folder "asiatech" to C:\xampp\htdocs\
2. In XAMPP Control Panel start ONLY Apache
3. Open http://localhost/asiatech/   (login: admin/admin123 | osa/osa123 | teacher/teacher123)
Other devices on the same Wi-Fi: http://<PC-IP>/asiatech/ (allow Apache in Windows Firewall)

Real data later: replace data/model_dataset.csv and data/violations.csv (same columns), then open http://localhost/asiatech/setup.php once to rebuild the database.
If you see "pdo_sqlite" error: open C:\xampp\php\php.ini, remove the ; before extension=pdo_sqlite, restart Apache.
