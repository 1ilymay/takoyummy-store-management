<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

function mailtrap_send_receipt(
    string $recipient,
    string $recipientName,
    string $subject,
    string $body,
    ?string $pdf = null
): void {

    $mail = new PHPMailer(true);

    $host = getenv('MAILTRAP_HOST') ?: 'sandbox.smtp.mailtrap.io';
    $port = (int) (getenv('MAILTRAP_PORT') ?: 2525);
    $username = getenv('MAILTRAP_USERNAME') ?: '';
    $password = getenv('MAILTRAP_PASSWORD') ?: '';
    $sender = getenv('MAILTRAP_SENDER_EMAIL') ?: 'orders@takoyummy.test';
    $senderName = getenv('MAILTRAP_SENDER_NAME') ?: 'Takoyummy';

    if ($username === '' || $password === '') {
        throw new RuntimeException('Mailtrap credentials are missing.');
    }

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid customer email address.');
    }

    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->Port = $port;

    if ($port === 465) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->setFrom($sender, $senderName);
    $mail->addAddress($recipient, $recipientName);

    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body = $body;

    
    if ($pdf !== null) {
    $mail->addStringAttachment(
        $pdf,
        'transaction-receipt.pdf',
        'base64',
        'application/pdf'
    );
}

    $mail->send();
}