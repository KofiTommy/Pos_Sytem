-- Optional manual migration for hosting. Run once if the application database
-- user cannot ALTER tables. Otherwise the application adds this automatically.
ALTER TABLE business_settings
    ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 5.00;
