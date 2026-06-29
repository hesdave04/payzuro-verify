# Payzuro Verify

Video verification recording system for verify.payzuro.com.

## Files
- `explore.php` — Session explorer with date sorting, download & combine buttons
- `download.php` — ZIP download endpoint for session video clips  
- `init.php` — Session initialization with account email tracking
- `upload.php` — Video chunk upload handler (to be added)

## Account Tracking
Sessions capture the referring account email via `?account=email` URL parameter.
Data is stored in `records/{session_id}/account.json`.

## Hosted on
Hostinger — verify.payzuro.com
