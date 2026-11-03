# Mailtrap email setup

Configure these environment variables for the PHP/Apache process. Use the SMTP credentials from your Mailtrap Email Testing inbox; do not put credentials in the repository.

- `MAILTRAP_HOST`
- `MAILTRAP_PORT` (usually `2525`; port `465` uses implicit TLS)
- `MAILTRAP_USERNAME`
- `MAILTRAP_PASSWORD`
- `MAILTRAP_SENDER_EMAIL` (verified sender address in Mailtrap)
- `MAILTRAP_SENDER_NAME` (optional; defaults to `Takoyaki Shop`)

After setting them, restart Apache so PHP receives the variables. When an admin changes an order status, the app commits the prepared-statement status and inventory transaction first, then emails the customer an itemized subtotal/grand total and attaches `transaction-receipt.pdf`. If delivery fails, the order update remains saved and the admin sees an email warning; the PHP error log contains the SMTP failure.
