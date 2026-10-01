# ZAS Export Docs AI System

Professional PHP/MySQL web app for:

- Commercial Invoice records
- Packing List records
- Excel import preview
- Manual correction before approval
- Role-based access
- Admin allocation of shipments/customers
- Approval and lock control
- Admin amendment audit trail
- OpenAI `text-embedding-3-small` embedding storage
- GPT search answer using `gpt-5-mini`

## Main Roles

1. **Admin**
   - Full access
   - Manage users
   - Assign shipments
   - Approve and lock
   - Amend locked records with audit reason
   - Create/regenerate embeddings
   - AI search all records

2. **Colleague**
   - Access only assigned shipments
   - Create/edit draft commercial invoice and packing list
   - Can see rates only if Admin allows
   - Cannot approve, unlock, delete, or manage users

3. **Staff**
   - Access assigned packing list only
   - Cannot see rate, amount, invoice value, payment terms, charges
   - Cannot use AI search
   - Cannot approve or amend locked records

## Installation

1. Upload this folder to your subdomain.
2. Edit `config/config.php`.
3. Open `/install.php` in browser.
4. Create default admin account.
5. Delete or rename `install.php` after installation.
6. Login via `/login.php`.

## OpenAI API

In `config/config.php`:

```php
'openai_api_key' => 'sk-...',
'embedding_model' => 'text-embedding-3-small',
'ai_answer_model' => 'gpt-5-mini',
```

API key must stay server-side only.

## Security Notes

- Do not store API key in JavaScript.
- Use HTTPS.
- Delete `install.php` after installation.
- Put `storage/` outside webroot if possible.
- This app includes `.htaccess` deny rules for sensitive folders.
- Staff rate hiding is enforced in PHP views and save logic, not only CSS.
