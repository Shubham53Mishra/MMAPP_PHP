<?php
// Example route file for HTTP endpoints documentation
header('Content-Type: text/plain');
echo "API ROUTES:\n";
echo "POST   /backend/user/register.php         - User registration\n";
echo "POST   /backend/user/login.php            - User login\n";
echo "POST   /backend/user/image_upload.php     - User image upload\n";
echo "GET    /backend/user/profile.php           - Get user profile\n";
echo "POST   /backend/vendor/register.php        - Vendor registration\n";
echo "POST   /backend/vendor/login.php           - Vendor login\n";
echo "POST   /backend/vendor/image_upload.php     - Vendor image upload\n";
echo "GET    /backend/vendor/profile.php          - Get vendor profile\n";
echo "\n";
echo "All protected routes require: Authorization: Bearer <token> header.\n";
?>
