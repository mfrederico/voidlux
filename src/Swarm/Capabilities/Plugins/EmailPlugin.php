<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities\Plugins;

use VoidLux\Swarm\Capabilities\PluginInterface;
use VoidLux\Swarm\Model\{AgentModel, TaskModel};

/**
 * Email communication plugin.
 *
 * Provides context for sending/receiving emails via PHP or CLI tools.
 * Agents can use PHP's mail(), composer packages, or command-line tools.
 *
 * Capabilities: email, smtp, imap, communication
 */
class EmailPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'email';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Email sending and receiving via PHP, Composer packages, or CLI tools';
    }

    public function getCapabilities(): array
    {
        return ['email', 'smtp', 'imap', 'communication'];
    }

    public function getRequirements(): array
    {
        return ['php', 'composer'];
    }

    public function checkAvailability(): bool
    {
        exec('which php 2>/dev/null', $output, $phpCode);
        exec('which composer 2>/dev/null', $output, $composerCode);
        return $phpCode === 0 && $composerCode === 0;
    }

    public function install(): array
    {
        // PHP should already be available
        // Suggest installing PHPMailer via composer if needed
        return [
            'success' => true,
            'message' => 'Email plugin ready. Install phpmailer/phpmailer via composer for advanced features.',
        ];
    }

    public function injectPromptContext(TaskModel $task, AgentModel $agent): string
    {
        return <<<'CONTEXT'
## Email Communication Available

You can send and receive emails using PHP or command-line tools. Use your native **Bash** tool:

### Option 1: PHP Script (Recommended for Complex Emails)

Create a PHP script for sending emails:

```php
<?php
// send-email.php
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer(true);

// SMTP configuration
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;
$mail->Username = 'your-email@gmail.com';
$mail->Password = 'your-app-password';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port = 587;

// Email content
$mail->setFrom('from@example.com', 'Sender Name');
$mail->addAddress('to@example.com', 'Recipient Name');
$mail->Subject = 'Email Subject';
$mail->Body = 'Email body content';
$mail->isHTML(true);  // or false for plain text

$mail->send();
echo "Email sent successfully\n";
?>
```

Run via Bash:
```bash
# Install PHPMailer first
composer require phpmailer/phpmailer

# Send email
php send-email.php
```

### Option 2: Command-Line (Quick & Simple)

```bash
# Using mail command (requires postfix/sendmail)
echo "Email body" | mail -s "Subject" recipient@example.com

# Using mailx with attachment
echo "Body text" | mailx -s "Subject" -a /path/to/file.pdf recipient@example.com

# Using curl with SMTP
curl --url 'smtps://smtp.gmail.com:465' \
  --ssl-reqd \
  --mail-from 'sender@gmail.com' \
  --mail-rcpt 'recipient@example.com' \
  --user 'sender@gmail.com:app-password' \
  --upload-file email.txt
```

### Option 3: IMAP Reading (PHP)

```php
<?php
// read-emails.php
$mailbox = imap_open(
    '{imap.gmail.com:993/imap/ssl}INBOX',
    'your-email@gmail.com',
    'your-app-password'
);

$emails = imap_search($mailbox, 'UNSEEN');  // Unread emails

foreach ($emails as $emailNum) {
    $header = imap_headerinfo($mailbox, $emailNum);
    $body = imap_body($mailbox, $emailNum);

    echo "From: {$header->fromaddress}\n";
    echo "Subject: {$header->subject}\n";
    echo "Body: {$body}\n\n";
}

imap_close($mailbox);
?>
```

### Email File Format (for curl method)

Create `email.txt`:
```
From: "Sender" <sender@example.com>
To: "Recipient" <recipient@example.com>
Subject: Your Subject Here

This is the email body.
Multiple lines supported.
```

### Tips
- **Gmail**: Use app-specific passwords, not your main password
- **Environment variables**: Store credentials in `.env` files, not hardcoded
- **Testing**: Use mailtrap.io or mailhog for development
- **Attachments**: PHPMailer has `addAttachment('/path/to/file.pdf')`
- **HTML emails**: Set `$mail->isHTML(true)` and use HTML in body
- **Read receipts**: `$mail->ConfirmReadingTo = 'sender@example.com'`

### Common SMTP Providers
- Gmail: `smtp.gmail.com:587` (STARTTLS) or `:465` (SSL)
- Outlook: `smtp-mail.outlook.com:587`
- Yahoo: `smtp.mail.yahoo.com:587`
- SendGrid: `smtp.sendgrid.net:587`
- Mailgun: `smtp.mailgun.org:587`

CONTEXT;
    }

    public function onEnable(string $agentId): void
    {
        // No state to initialize
    }

    public function onDisable(string $agentId): void
    {
        // No cleanup needed
    }
}
