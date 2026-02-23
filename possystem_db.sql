-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 24, 2026 at 12:01 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `possystem_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `business_settings`
--

CREATE TABLE `business_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `business_name` varchar(180) NOT NULL,
  `business_email` varchar(160) NOT NULL,
  `contact_number` varchar(40) NOT NULL,
  `logo_filename` varchar(255) DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `business_settings`
--

INSERT INTO `business_settings` (`id`, `business_name`, `business_email`, `contact_number`, `logo_filename`, `updated_at`) VALUES
(1, 'Mother Care', 'info@mothercare.com', '+233 000 000 000', '', '2026-02-23 22:20:57');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'Feeding & Nursing', 'Bottles, nipples, feeding sets', '2026-02-23 13:00:10'),
(2, 'Diapers & Wipes', 'Diapers, wipes, and changing accessories', '2026-02-23 13:00:10'),
(3, 'Clothing', 'Baby clothing and accessories', '2026-02-23 13:00:10'),
(4, 'Safety & Health', 'Safety products and health monitors', '2026-02-23 13:00:10'),
(5, 'Toys & Entertainment', 'Toys and entertainment items', '2026-02-23 13:00:10'),
(6, 'Bath & Care', 'Bath products and skincare', '2026-02-23 13:00:10');

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `phone` varchar(40) DEFAULT '',
  `subject` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `admin_reply` text DEFAULT NULL,
  `status` varchar(30) DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `customer_name` varchar(200) NOT NULL,
  `customer_email` varchar(150) NOT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `city` varchar(100) NOT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `tax` decimal(10,2) DEFAULT 0.00,
  `shipping` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `customer_name`, `customer_email`, `customer_phone`, `address`, `city`, `postal_code`, `subtotal`, `tax`, `shipping`, `total`, `notes`, `status`, `created_at`, `updated_at`) VALUES
(8, 'Walk-in Customer', 'pos@mothercare.local', 'N/A', 'In-store POS', 'N/A', 'N/A', 50.00, 5.00, 0.00, 55.00, 'POS Sale | Payment: cash | Tax Rate: 10.00% | Discount: 0.00', 'paid', '2026-02-23 22:49:17', '2026-02-23 22:49:17');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(200) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `product_name`, `quantity`, `price`, `created_at`) VALUES
(12, 8, 11, 'Cerelac', 1, 50.00, '2026-02-23 22:49:17');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `featured` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `description`, `price`, `category_id`, `category`, `image`, `stock`, `featured`, `created_at`) VALUES
(1, 'Premium Baby Bottle Set', 'Complete feeding bottle set with sterilizer', 45.99, NULL, 'Feeding & Nursing', 'bottle.jpg', 15, 1, '2026-02-23 13:00:10'),
(2, 'Organic Baby Diapers', 'Size 1 - Newborn, 200 count pack', 32.50, NULL, 'Diapers & Wipes', 'diapers.jpg', 21, 1, '2026-02-23 13:00:10'),
(3, 'Soft Baby Clothing Set', '5-piece organic cotton clothing set', 55.00, NULL, 'Clothing', 'clothing.jpg', 12, 1, '2026-02-23 13:00:10'),
(4, 'Digital Baby Monitor', '5-inch screen with night vision', 85.99, NULL, 'Safety & Health', 'monitor.jpg', 8, 0, '2026-02-23 13:00:10'),
(5, 'Teething Toy Set', '4 safe silicone teething toys', 18.99, NULL, 'Toys & Entertainment', 'teething.jpg', 26, 1, '2026-02-23 13:00:10'),
(6, 'Baby Bath Tub', 'Compact foldable baby bath tub', 39.99, NULL, 'Bath & Care', 'bathtub.jpg', 12, 0, '2026-02-23 13:00:10'),
(7, 'Nursing Pillow', 'Ergonomic nursing and support pillow', 42.50, NULL, 'Feeding & Nursing', 'nursing-pillow.jpg', 14, 0, '2026-02-23 13:00:10'),
(8, 'Wet Wipes Pack', '800 count hypoallergenic wipes', 24.99, NULL, 'Diapers & Wipes', 'wipes.jpg', 30, 1, '2026-02-23 13:00:10'),
(9, 'Baby Stroller', 'Lightweight foldable stroller', 189.99, NULL, 'Clothing', 'stroller.jpg', 5, 0, '2026-02-23 13:00:10'),
(10, 'Crib Sheets Set', '3-piece crib sheet set', 29.99, NULL, 'Bath & Care', 'sheets.jpg', 18, 0, '2026-02-23 13:00:10'),
(11, 'Cerelac', '', 50.00, NULL, 'Feeding & Nursing', '', 6, 0, '2026-02-23 22:45:27');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'admin', 'admin@mothercare.com', '$2y$12$yIuUeZxPfHwKootQG/JkiuKDNgnFOL4HmMP/yz2/a7Y0/aQh5bgQO', 'admin', '2026-02-23 13:00:10'),
(2, 'staff', 'staff@gmail.com', '$2y$10$.nxXcho9Utas4jAvunegQOFbl3IU0SFAfzdAqpstb8PN3PYMtfr1G', 'sales', '2026-02-23 20:47:29');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `business_settings`
--
ALTER TABLE `business_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contact_created_at` (`created_at`),
  ADD KEY `idx_contact_status` (`status`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `customer_email` (`customer_email`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `idx_orders_customer_email` (`customer_email`),
  ADD KEY `idx_orders_status` (`status`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_items_order_id` (`order_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_products_featured` (`featured`),
  ADD KEY `idx_products_category` (`category`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
