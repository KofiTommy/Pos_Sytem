# Mother Care - POS System with E-Commerce (Multi-Tenant Ready)

A complete POS system with a public-facing e-commerce store for a mother care business.

## 📋 Project Structure

```
possystem/
├── index.html                 # Landing page
├── css/
│   └── style.css             # Custom styles
├── js/
│   └── scripts.js            # JavaScript functions
├── pages/
│   ├── products.html         # Product catalog
│   ├── cart.html             # Shopping cart
│   ├── login.html            # Admin login
│   └── admin/
│       ├── dashboard.html    # Admin dashboard (to be created)
│       └── manage-products.html # Product management (to be created)
├── php/
│   ├── db-connection.php     # Database connection
│   ├── get-products.php      # Fetch products
│   ├── login.php             # Admin authentication
│   ├── logout.php            # Logout
│   ├── process-order.php     # Order processing
│   └── admin/                # Admin-related PHP files
├── assets/
│   └── images/               # Product images
└── database_setup.sql        # Database initialization
```

## 🚀 Quick Start Guide

### Step 1: Database Setup

1. Open **phpMyAdmin** (<http://localhost/phpmyadmin>)
2. Create a new database named `possystem_db`
3. Go to the SQL tab and copy/paste the contents of `database_setup.sql`
4. Execute the SQL

**OR** use command line:

```bash
mysql -u root < database_setup.sql
```

### Step 2: Database Connection

The `php/db-connection.php` file is pre-configured for XAMPP defaults:

- **Host**: localhost
- **Username**: root
- **Password**: (empty)
- **Database**: possystem_db

If your settings are different, update `php/db-connection.php`:

```php
$host = 'your_host';
$username = 'your_username';
$password = 'your_password';
$database = 'possystem_db';
```

### Step 3: Access the Application

1. Start Apache and MySQL from XAMPP Control Panel
2. Open your browser and go to: `http://localhost/possystem/`

### Step 4: Apply Multi-Tenant Migration (Recommended)

Run:

```sql
sql/multi_tenant_saas_migration.sql
```

This adds the `businesses` table and `business_id` isolation across users, products, sales, checkout, and payment data.

### Step 5: Admin Login

- **URL**: <http://localhost/possystem/pages/login.html>
- **Username**: admin
- **Password**: admin123
- **Business Code**: `mother-care` (or leave empty for the default tenant)

### Optional: Create New Business (Owner Signup API)

`POST /php/register-business.php`

Example JSON body:

```json
{
  "business_name": "Acme Retail",
  "business_email": "owner@acme.test",
  "contact_number": "+1 555 0100",
  "business_code": "acme-retail",
  "owner_username": "acmeowner",
  "owner_email": "owner@acme.test",
  "owner_password": "StrongPass!2026",
  "subscription_plan": "starter"
}
```

## 🔧 Features Implemented

### ✅ Customer-Facing Features

- [x] Modern responsive landing page
- [x] Product catalog with filters and sorting
- [x] Shopping cart with local storage
- [x] Checkout process with customer details
- [x] Order placement and confirmation

### ✅ Backend Features

- [x] Database design and setup
- [x] Product management
- [x] Order processing
- [x] Admin authentication

### ⏳ Features to Build Next

- [ ] Admin dashboard with order management
- [ ] Product management panel
- [ ] Order status updates
- [ ] Customer notifications (email)
- [ ] Payment integration
- [ ] Sales reports and analytics
- [ ] Inventory management
- [ ] Admin user management

## 📁 Adding Product Images

1. Add product images to `assets/images/` folder
2. Use the filename in the database (e.g., 'bottle.jpg')
3. Database query will reference these files

**Note**: Currently using placeholder filenames. Replace with actual image uploads as needed.

## 🛡️ Security Notes

### Current Implementation

- ✅ Password hashing with bcrypt
- ✅ Session-based authentication
- ✅ Input validation on server-side
- ✅ CSRF protection ready (add tokens for production)
- ✅ SQL injection prevention with prepared statements

### For Production

- [ ] Enable HTTPS/SSL
- [ ] Add CSRF tokens to forms
- [ ] Implement rate limiting
- [ ] Add email verification
- [ ] Set up secure payment gateway
- [ ] Regular security audits

## 📝 Creating Additional Admin Users

Use phpMyAdmin or run this SQL:

```sql
-- Replace username, email, and password hash as needed
INSERT INTO users (username, email, password, role) 
VALUES ('newuser', 'email@example.com', '[HASHED_PASSWORD]', 'admin');
```

To generate password hash in PHP:

```php
echo password_hash('your_password', PASSWORD_BCRYPT);
```

## 🗄️ Database Schema Overview

### products

- id, name, description, price, category, image, stock, featured, created_at

### orders

- id, customer_name, customer_email, customer_phone, address, city, postal_code, subtotal, tax, shipping, total, notes, status, created_at

### order_items

- id, order_id, product_id, product_name, quantity, price

### users

- id, username, email, password, role, created_at

## 🎨 Customization Tips

### Colors

Edit `css/style.css` to change the primary color (#667eea) to your brand color.

### Company Info

Update these in relevant files:

- Company name: "Mother Care"
- Contact email: <info@mothercare.com>
- Phone: +1 (123) 456-7890

### Product Categories

Edit `database_setup.sql` to add/remove categories before initial setup.

## 📞 Support & Next Steps

1. **Upload product images** to `assets/images/`
2. **Add products** through the admin panel (when built)
3. **Set up payment gateway** (Stripe, PayPal, etc.)
4. **Configure email notifications**
5. **Deploy to production server**

## 📝 License

This project is created for Mother Care business. All rights reserved.

---

**Built with**: HTML5, Bootstrap 5, CSS3, JavaScript (ES6), PHP 7+, MySQL

**Last Updated**: February 23, 2026

## Paystack Mobile Money Setup

This project includes Paystack Mobile Money checkout with:

- `php/paystack-init.php` (initialize payment)
- `php/paystack-verify.php` (verify + finalize order)
- `php/paystack-webhook.php` (server-to-server webhook)

### 1. Configure secret key on server

Set environment variable:

- `PAYSTACK_SECRET_KEY=sk_test_xxx` (sandbox) or `sk_live_xxx` (production)
- Optional (recommended for UI-managed keys): `PAYMENT_SETTINGS_KEY=your-long-random-secret`

### 2. Configure webhook URL in Paystack dashboard

- `https://your-domain.com/possystem/php/paystack-webhook.php`

For local XAMPP testing, use a public tunnel URL and map webhook to it.

### 3. Apply migration once

Run SQL file:

- `sql/paystack_migration.sql`

The app also auto-creates payment schema at runtime, but migration is recommended for production.

### Admin UI Configuration

Owner can configure Paystack from:

- `pages/admin/payment-settings.php`

If `PAYSTACK_SECRET_KEY` is set in environment, it overrides DB key in runtime.
