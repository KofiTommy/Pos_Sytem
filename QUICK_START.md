# 🚀 Quick Start - Database Setup

If you're getting the **"Invalid username or password"** error on login, your database hasn't been set up yet. Follow these steps:

## ⚡ 3-Minute Setup

### Step 1: Start Services

1. Open **XAMPP Control Panel**
2. Click **Start** for Apache
3. Click **Start** for MySQL

### Step 2: Create Database

Choose **ONE** of these options:

#### Option A: phpMyAdmin (Easiest)

```
1. Open: http://localhost/phpmyadmin
2. Click "New" → Type: possystem_db
3. Click "Create"
4. Click "SQL" tab
5. Copy-paste ENTIRE content from: database_setup.sql
6. Click "Go" or "Execute"
7. ✓ Done!
```

#### Option B: Command Line

```bash
# Navigate to your project directory
cd c:\xampp\htdocs\possystem

# Run the SQL file
mysql -u root < database_setup.sql

# Password: (press Enter - it's empty by default)
```

#### Option C: MySQL Command Line

```bash
# Open MySQL Command Line
mysql -u root

# Paste these commands:
CREATE DATABASE possystem_db;
USE possystem_db;

# Then copy-paste the entire content of database_setup.sql
```

### Step 3: Verify Setup

1. Go to: `http://localhost/possystem/pages/setup.html`
2. The page will automatically check:
   - ✓ PHP version
   - ✓ Database connection
   - ✓ Admin user exists
   - ✓ All tables created
   - ✓ Sample products loaded

### Step 4: Enable Multi-Tenant Mode (Recommended)

Run this SQL file once:

```sql
sql/multi_tenant_saas_migration.sql
```

### Step 5: Test Login

1. Go to: `http://localhost/possystem/pages/login.html`
2. Login with:
   - **Username**: `admin`
   - **Password**: `admin123`
   - **Business Code**: `mother-care` (or leave empty for default tenant)
3. ✓ Success!

---

## 📁 What Gets Created

The `database_setup.sql` file creates:

| Table | Records | Purpose |
|-------|---------|---------|
| **products** | 10 | Sample baby care products |
| **orders** | 0 | Customer orders |
| **order_items** | 0 | Items in each order |
| **users** | 1 | Admin user (username: admin) |
| **categories** | 6 | Product categories |

---

## 🔍 Troubleshooting

### "Access denied for user 'root'"

- **Solution**: Check that MySQL is running in XAMPP Control Panel
- **Or**: Update `php/db-connection.php` with correct credentials

### "database_setup.sql not found"

- **Solution**: Make sure the file is in: `c:\xampp\htdocs\possystem\`
- **Or**: Copy the SQL content from README.md

### "Table already exists"

- **Solution**: You've run the setup before. That's OK! The dashboard is ready to use.
- **Or**: Delete the database and recreate it

### "Specified key was too long"

- **Solution**: MySQL version issue. Update mysql to latest, or run this first:

```sql
SET GLOBAL max_allowed_packet=67108864;
```

### Still getting "Invalid username or password"?

1. Run the **Setup Diagnostic** page: `http://localhost/possystem/pages/setup.html`
2. It will tell you exactly what's wrong
3. Follow the instructions provided

---

## ✅ Success Checklist

After setup, you should see:

- [ ] **Setup Diagnostic** shows all checks as "OK"
- [ ] **Admin Login** works (username: admin, password: admin123)
- [ ] **Products Page** shows 10 sample products
- [ ] **Shopping Cart** works
- [ ] **Create Order** button works on checkout

---

## 📝 Next Steps

1. ✓ Database is set up
2. → Add product images to `assets/images/`
3. → Build Admin Dashboard (under construction)
4. → Add more admin users
5. → Deploy to production

---

## 💾 Database Credentials

```
Host:     localhost
Database: possystem_db
Username: root
Password: (empty)
Port:     3306
```

**Change these in `php/db-connection.php` if your setup is different**

---

## 🔐 Change Default Admin Password

After first login, change the admin password:

```sql
-- Generate hash in PHP first:
-- echo password_hash('newpassword', PASSWORD_BCRYPT);
-- Then run (replace hash):

UPDATE users 
SET password = '$2y$10$[YOUR_HASHED_PASSWORD_HERE]' 
WHERE username = 'admin';
```

---

**Need help?** Check the Setup Diagnostic page at: `http://localhost/possystem/pages/setup.html`
