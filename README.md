vigilo-security-platform

 Description
Vigilo is a security and monitoring platform that helps users track locations, manage documents, and secure items. It integrates QR code scanning for items and OpenStreetMap for real-time mapping.

 Features
- User authentication and OTP verification
- Admin dashboard with stats, activity logs, and user management
- File/document management (upload, download, share)
- QR code scanning for item verification
- Location tracking using OpenStreetMap
- Public and private user profiles

 Tech Stack
- Backend: PHP, MySQL
- Frontend: HTML, CSS, JavaScript
- Mapping: OpenStreetMap
- QR Code Scanner: JavaScript/PHP library
- Local development: XAMPP

 Setup
1. Install XAMPP and MySQL
2. Copy project files to `htdocs/vigilo`
3. Create database and import tables using `check_tables.php`
4. Update `/config/db.php` with your database credentials
5. Open `http://localhost/vigilo/public/index.php` in browser

 Note
Uploads folder not included in repository. Add your own test data if needed.

