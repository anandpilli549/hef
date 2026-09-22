<?php
/**
 * Notification provider settings. Fill in real credentials as you get
 * them. Nothing in global.php below breaks if a provider's keys are
 * still blank — it just logs a failed attempt instead of crashing.
 */

// --- Mail ---
// 'php_mail' works immediately with no setup but is unreliable on
// IONOS shared hosting (spam-filtered / blocked). Switch to 'smtp'
// once you have a transactional provider (Brevo, Resend, SES, etc.)
// and fill in the SMTP_* values below.
define('MAIL_DRIVER', 'php_mail'); // 'php_mail' | 'smtp'
define('MAIL_FROM_ADDRESS', 'no-reply@cthkennels.com');
define('MAIL_FROM_NAME', 'HEFarm');

define('SMTP_HOST', '');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('SMTP_ENCRYPTION', 'tls'); // 'tls' | 'ssl'

// --- SMS (MSG91) ---
define('MSG91_AUTH_KEY', '');
define('MSG91_SENDER_ID', 'HEFAPP');
define('MSG91_ROUTE', '4'); // 4 = transactional

// --- WhatsApp (Meta WhatsApp Business Cloud API) ---
define('WHATSAPP_PHONE_NUMBER_ID', '');
define('WHATSAPP_ACCESS_TOKEN', '');
define('WHATSAPP_API_VERSION', 'v20.0');

// --- Storefront / Payments ---
define('RAZORPAY_KEY_ID', '');       // fill in your Razorpay Key ID
define('RAZORPAY_KEY_SECRET', '');   // fill in your Razorpay Key Secret
define('DELIVERY_FLAT_FEE', 50.00);  // flat delivery fee in INR, adjust as needed

// --- HEFarm's OWN Razorpay account (platform billing — charging companies
// to use HEFarm). Reuses the super-admin's own company's Razorpay
// credentials (see hef_get_platform_razorpay_credentials() in db.php)
// rather than requiring a separate, duplicate set of keys. ---

// --- The platform operator's own company (you) never needs to pay for
// HEFarm itself — exempt from all trial/subscription expiry checks and
// billing prompts. ---
define('HEF_OWNER_COMPANY_ID', 1);
define('CRON_SECRET', 'QxRu3ONbgMxffqxqeienUVQfQWi72LCT13Jhmtay');

