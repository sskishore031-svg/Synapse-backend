<?php
// SMTP Mailer Configuration for Synapse AI
// Update these settings with your Gmail or SMTP provider credentials

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls'); // 'tls' or 'ssl'
define('SMTP_USERNAME', 'your_email@gmail.com'); // Replace with your Gmail address
define('SMTP_PASSWORD', 'your_gmail_app_password'); // Replace with your 16-character Gmail App Password
define('SMTP_FROM_EMAIL', 'your_email@gmail.com');
define('SMTP_FROM_NAME', 'Synapse AI Support');

/**
 * Sends password reset email using PHPMailer or native mail with HTML template
 */
function sendResetEmail($toEmail, $resetUrl) {
    // If PHPMailer is installed via composer or placed in vendor/
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }

    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($toEmail);

            $mail->isHTML(true);
            $mail->Subject = 'Synapse AI - Password Reset Request';
            $mail->Body    = getResetEmailHtml($resetUrl);
            $mail->AltBody = "Reset your Synapse AI password by opening this link: " . $resetUrl;

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: " . $mail->ErrorInfo);
            return false;
        }
    } else {
        // Fallback using native PHP mail() / SMTP headers
        $subject = 'Synapse AI - Password Reset Request';
        $body = getResetEmailHtml($resetUrl);
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">" . "\r\n";

        return @mail($toEmail, $subject, $body, $headers);
    }
}

function getResetEmailHtml($resetUrl) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #090D22; color: #ffffff; padding: 20px; }
            .container { max-width: 500px; margin: 0 auto; background: #0F172A; border-radius: 16px; padding: 30px; border: 1px solid #1E293B; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
            .logo { text-align: center; font-size: 24px; font-weight: bold; color: #38BDF8; margin-bottom: 20px; letter-spacing: 1px; }
            .btn { display: block; width: 80%; margin: 25px auto; padding: 14px; text-align: center; background: linear-gradient(90deg, #8B5CF6, #3B82F6, #06B6D4); color: #ffffff; text-decoration: none; border-radius: 12px; font-weight: bold; font-size: 16px; }
            .footer { margin-top: 25px; text-align: center; font-size: 12px; color: #64748B; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="logo">⚡ SYNAPSE AI</div>
            <h2 style="text-align: center; color: #F8FAFC;">Password Reset Request</h2>
            <p style="color: #94A3B8; font-size: 14px; line-height: 1.5;">You requested a password reset for your Synapse AI account. Click the button below to set a new password. This link is single-use and will expire in 30 minutes.</p>
            <a href="' . htmlspecialchars($resetUrl) . '" class="btn">Reset My Password</a>
            <p style="color: #64748B; font-size: 12px;">If you did not request this reset, you can safely ignore this email.</p>
            <div class="footer">&copy; Synapse AI · All rights reserved</div>
        </div>
    </body>
    </html>
    ';
}
