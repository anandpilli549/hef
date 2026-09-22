-- phpMyAdmin SQL Dump
-- version 4.9.11
-- https://www.phpmyadmin.net/
--
-- Host: db5021374724.hosting-data.io
-- Generation Time: Sep 22, 2026 at 11:00 AM
-- Server version: 11.8.8-MariaDB-log
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dbs16102030`
--

-- --------------------------------------------------------

--
-- Table structure for table `alerts_log`
--

CREATE TABLE `alerts_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('vaccination_due','feed_low','subscription_expiring','booking_refund','room_capacity') NOT NULL,
  `reference_type` varchar(255) DEFAULT NULL,
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `triggered_at` timestamp NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `table_name` varchar(64) NOT NULL,
  `record_id` bigint(20) UNSIGNED NOT NULL,
  `action` enum('update','delete') NOT NULL,
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `restored_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `backup_events`
--

CREATE TABLE `backup_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` enum('backup','restore') NOT NULL,
  `scope` enum('company','company_full','database') NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `breed_id` bigint(20) UNSIGNED DEFAULT NULL,
  `room_id` bigint(20) UNSIGNED DEFAULT NULL,
  `batch_code` varchar(255) NOT NULL,
  `date_acquired` date NOT NULL,
  `age_at_acquisition_days` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `initial_count_male` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `initial_count_female` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `initial_count_unknown` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `current_count_male` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `current_count_female` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `current_count_unknown` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `source_supplier` varchar(255) DEFAULT NULL,
  `purchase_cost` decimal(12,2) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` enum('active','sold_out','closed') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_email` varchar(255) NOT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `fulfillment_type` enum('pickup','delivery') NOT NULL,
  `delivery_address` text DEFAULT NULL,
  `delivery_pincode` varchar(10) DEFAULT NULL,
  `delivery_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'INR',
  `status` enum('pending_payment','paid','confirmed','fulfilled','unavailable','refund_requested','refunded','replacement_offered','resolved','cancelled') NOT NULL DEFAULT 'pending_payment',
  `razorpay_order_id` varchar(255) DEFAULT NULL,
  `razorpay_payment_id` varchar(255) DEFAULT NULL,
  `reserved_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `booking_items`
--

CREATE TABLE `booking_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `storefront_listing_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `breeds`
--

CREATE TABLE `breeds` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `banner_path` varchar(255) DEFAULT NULL,
  `owner_photo_path` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'Asia/Kolkata',
  `currency_code` char(3) NOT NULL DEFAULT 'INR',
  `status` enum('trial','active','expired','cancelled') NOT NULL DEFAULT 'trial',
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `salary_day` tinyint(3) UNSIGNED NOT NULL DEFAULT 5,
  `salary_divisor` smallint(5) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_subscriptions`
--

CREATE TABLE `company_subscriptions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `subscription_plan_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('trial','active','expired','cancelled') NOT NULL DEFAULT 'trial',
  `starts_at` timestamp NOT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `razorpay_subscription_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_notes`
--

CREATE TABLE `contact_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `contact_type` enum('customer','supplier') NOT NULL,
  `contact_id` bigint(20) UNSIGNED NOT NULL,
  `note` text NOT NULL,
  `noted_at` datetime NOT NULL,
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `update_token` varchar(64) DEFAULT NULL,
  `update_token_created_at` timestamp NULL DEFAULT NULL,
  `last_customer_update_at` timestamp NULL DEFAULT NULL,
  `status` enum('active','closed') NOT NULL DEFAULT 'active',
  `status_reason` varchar(40) DEFAULT NULL,
  `status_changed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_species`
--

CREATE TABLE `customer_species` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `breed_id` bigint(20) UNSIGNED DEFAULT NULL,
  `age_days` int(10) UNSIGNED DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_pricing_tiers`
--

CREATE TABLE `delivery_pricing_tiers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `min_distance_km` decimal(6,2) NOT NULL,
  `max_distance_km` decimal(6,2) DEFAULT NULL,
  `fee` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `egg_production`
--

CREATE TABLE `egg_production` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `count_for_sale` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `count_for_incubation` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `category` enum('purchase','vaccination','feed','equipment','utility','labor','transportation','other') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'INR',
  `date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feed_consumption`
--

CREATE TABLE `feed_consumption` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `unit` enum('g','kg','ml','l') NOT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feed_purchases`
--

CREATE TABLE `feed_purchases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED DEFAULT NULL,
  `feed_type_name` varchar(255) NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `unit` enum('g','kg','ml','l') NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feed_types`
--

CREATE TABLE `feed_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `growth_stage` varchar(100) DEFAULT NULL,
  `quantity_per_animal_per_day` decimal(8,3) NOT NULL,
  `unit` enum('g','kg','ml','l') NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_records`
--

CREATE TABLE `health_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `symptoms` text DEFAULT NULL,
  `treatment` text DEFAULT NULL,
  `medicine_cost` decimal(10,2) DEFAULT NULL,
  `vet_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `income`
--

CREATE TABLE `income` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_sold` int(10) UNSIGNED DEFAULT NULL,
  `gender_sold` enum('male','female','unknown') DEFAULT NULL,
  `source` enum('animal_sale','egg_sale','meat_sale','booking') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'INR',
  `date` date NOT NULL,
  `related_booking_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `incubation_batches`
--

CREATE TABLE `incubation_batches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `source_batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `eggs_set` int(10) UNSIGNED NOT NULL,
  `fertile_count` int(10) UNSIGNED DEFAULT NULL,
  `date_set` date NOT NULL,
  `expected_hatch_date` date NOT NULL,
  `actual_hatch_date` date DEFAULT NULL,
  `hatched_count` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_permissions`
--

CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_roles`
--

CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mortality_log`
--

CREATE TABLE `mortality_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `count` int(10) UNSIGNED NOT NULL,
  `gender` enum('male','female','unknown') DEFAULT NULL,
  `cause` enum('disease','predator','weather','unknown','other') NOT NULL DEFAULT 'unknown',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_logs`
--

CREATE TABLE `notification_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `channel` enum('mail','sms','whatsapp') NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_settings`
--

CREATE TABLE `notification_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `msg91_auth_key` varchar(255) DEFAULT NULL,
  `msg91_sender_id` varchar(20) DEFAULT NULL,
  `sms_api_url` varchar(255) DEFAULT NULL,
  `whatsapp_phone_number_id` varchar(255) DEFAULT NULL,
  `whatsapp_access_token` text DEFAULT NULL,
  `whatsapp_alert_template` varchar(255) DEFAULT NULL,
  `whatsapp_booking_template` varchar(255) DEFAULT NULL,
  `whatsapp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `whatsapp_message_template` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_codes`
--

CREATE TABLE `otp_codes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `code` varchar(6) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_settings`
--

CREATE TABLE `payment_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `razorpay_key_id` varchar(255) DEFAULT NULL,
  `razorpay_key_secret` varchar(255) DEFAULT NULL,
  `razorpay_webhook_secret` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pincodes`
--

CREATE TABLE `pincodes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `pincode` varchar(10) NOT NULL,
  `latitude` decimal(10,6) NOT NULL,
  `longitude` decimal(10,6) NOT NULL,
  `district` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `refund_policies`
--

CREATE TABLE `refund_policies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `refund_percentage` decimal(5,2) NOT NULL DEFAULT 100.00,
  `replacement_allowed` tinyint(1) NOT NULL DEFAULT 1,
  `response_sla_hours` int(10) UNSIGNED NOT NULL DEFAULT 48,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `refund_requests`
--

CREATE TABLE `refund_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `reason` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL,
  `status` enum('pending','approved','rejected','replacement_offered','resolved') NOT NULL DEFAULT 'pending',
  `resolution_notes` text DEFAULT NULL,
  `refunded_amount` decimal(12,2) DEFAULT NULL,
  `razorpay_refund_id` varchar(255) DEFAULT NULL,
  `resolved_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_has_permissions`
--

CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `capacity` int(10) UNSIGNED DEFAULT NULL,
  `current_species_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `species`
--

CREATE TABLE `species` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(255) DEFAULT NULL,
  `max_days_to_sell` int(10) UNSIGNED DEFAULT NULL,
  `typical_mature_weight_kg` decimal(8,3) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `id1_label` varchar(60) DEFAULT NULL,
  `id1_path` varchar(255) DEFAULT NULL,
  `id2_label` varchar(60) DEFAULT NULL,
  `id2_path` varchar(255) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `monthly_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `joined_on` date DEFAULT NULL,
  `left_on` date DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_adjustments`
--

CREATE TABLE `staff_adjustments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `staff_id` bigint(20) UNSIGNED NOT NULL,
  `month` date NOT NULL,
  `kind` enum('addition','deduction') NOT NULL,
  `label` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_advances`
--

CREATE TABLE `staff_advances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `staff_id` bigint(20) UNSIGNED NOT NULL,
  `given_on` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `monthly_recovery` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recovered` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `expense_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_advance_recoveries`
--

CREATE TABLE `staff_advance_recoveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `payroll_id` bigint(20) UNSIGNED NOT NULL,
  `advance_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_attendance`
--

CREATE TABLE `staff_attendance` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `staff_id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `status` enum('present','absent','half_day','paid_leave','unpaid_leave','week_off') NOT NULL,
  `marked_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_employment_periods`
--

CREATE TABLE `staff_employment_periods` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `staff_id` bigint(20) UNSIGNED NOT NULL,
  `started_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_payroll`
--

CREATE TABLE `staff_payroll` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `staff_id` bigint(20) UNSIGNED NOT NULL,
  `month` date NOT NULL,
  `base_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deduction_days` decimal(5,1) NOT NULL DEFAULT 0.0,
  `attendance_deduction` decimal(10,2) NOT NULL DEFAULT 0.00,
  `additions` decimal(10,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(10,2) NOT NULL DEFAULT 0.00,
  `advance_recovery` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recovery_manual` tinyint(1) NOT NULL DEFAULT 0,
  `net_pay` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','paid') NOT NULL DEFAULT 'draft',
  `paid_on` date DEFAULT NULL,
  `payment_mode` enum('cash','bank','upi','other') DEFAULT NULL,
  `expense_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `storefront_listings`
--

CREATE TABLE `storefront_listings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `type` enum('animal','egg','meat','live') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'INR',
  `quantity_available` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `available_from` date DEFAULT NULL,
  `seo_title` varchar(255) DEFAULT NULL,
  `seo_description` text DEFAULT NULL,
  `og_image_path` varchar(255) DEFAULT NULL,
  `status` enum('draft','published','sold_out') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscription_plans`
--

CREATE TABLE `subscription_plans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `currency_code` char(3) NOT NULL DEFAULT 'INR',
  `billing_cycle` enum('monthly','yearly') NOT NULL,
  `max_users` int(10) UNSIGNED DEFAULT NULL,
  `max_batches` int(10) UNSIGNED DEFAULT NULL,
  `storefront_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `pro_features_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `features_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `phone2` varchar(20) DEFAULT NULL,
  `phone3` varchar(20) DEFAULT NULL,
  `phone4` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `update_token` varchar(64) DEFAULT NULL,
  `update_token_created_at` timestamp NULL DEFAULT NULL,
  `last_supplier_update_at` timestamp NULL DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `status_reason` varchar(40) DEFAULT NULL,
  `status_changed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_species`
--

CREATE TABLE `supplier_species` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `breed_id` bigint(20) UNSIGNED DEFAULT NULL,
  `age_days` int(10) UNSIGNED DEFAULT NULL,
  `rate` decimal(10,2) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `moq` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `role` enum('owner','worker','vet') NOT NULL DEFAULT 'worker',
  `invited_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('active','invited','disabled') NOT NULL DEFAULT 'invited',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `alerts_immediate` tinyint(1) NOT NULL DEFAULT 1,
  `digest_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_digest_on` date DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_devices`
--

CREATE TABLE `user_devices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `session_token` varchar(128) NOT NULL,
  `last_active_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_notes`
--

CREATE TABLE `user_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link_type` enum('batch','customer','supplier') DEFAULT NULL,
  `link_id` bigint(20) UNSIGNED DEFAULT NULL,
  `remind_on` date DEFAULT NULL,
  `repeat_rule` enum('none','daily','weekly','monthly','yearly') NOT NULL DEFAULT 'none',
  `status` enum('open','done') NOT NULL DEFAULT 'open',
  `is_shared` tinyint(1) NOT NULL DEFAULT 0,
  `last_reminded_on` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vaccination_records`
--

CREATE TABLE `vaccination_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `vaccination_schedule_id` bigint(20) UNSIGNED DEFAULT NULL,
  `date_given` date NOT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `given_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vaccination_schedules`
--

CREATE TABLE `vaccination_schedules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `age_in_days` int(10) UNSIGNED NOT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `recurrence_interval_days` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `weight_logs`
--

CREATE TABLE `weight_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `sample_size` int(10) UNSIGNED NOT NULL,
  `average_weight` decimal(8,3) NOT NULL,
  `unit` enum('g','kg') NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alerts_log`
--
ALTER TABLE `alerts_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_alertslog_company` (`company_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_table_record` (`table_name`,`record_id`),
  ADD KEY `fk_audit_logs_company` (`company_id`),
  ADD KEY `fk_audit_logs_user` (`user_id`);

--
-- Indexes for table `backup_events`
--
ALTER TABLE `backup_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_backup_events_company` (`company_id`,`created_at`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `batch_code` (`batch_code`),
  ADD KEY `fk_batches_company` (`company_id`),
  ADD KEY `fk_batches_species` (`species_id`),
  ADD KEY `fk_batches_room` (`room_id`),
  ADD KEY `fk_batches_breed` (`breed_id`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_bookings_company` (`company_id`);

--
-- Indexes for table `booking_items`
--
ALTER TABLE `booking_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_bookingitems_booking` (`booking_id`),
  ADD KEY `fk_bookingitems_listing` (`storefront_listing_id`);

--
-- Indexes for table `breeds`
--
ALTER TABLE `breeds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_breed_species_name` (`species_id`,`name`),
  ADD KEY `fk_breeds_company` (`company_id`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `company_subscriptions`
--
ALTER TABLE `company_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_company_subscriptions_company` (`company_id`),
  ADD KEY `fk_company_subscriptions_plan` (`subscription_plan_id`);

--
-- Indexes for table `contact_notes`
--
ALTER TABLE `contact_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contact_notes_contact` (`company_id`,`contact_type`,`contact_id`,`noted_at`),
  ADD KEY `fk_contact_notes_user` (`created_by_user_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_customer_update_token` (`update_token`),
  ADD KEY `fk_customers_company` (`company_id`);

--
-- Indexes for table `customer_species`
--
ALTER TABLE `customer_species`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_customer_species_species` (`species_id`),
  ADD KEY `idx_customer_species_lookup` (`customer_id`,`species_id`,`breed_id`,`age_days`),
  ADD KEY `fk_customer_species_breed` (`breed_id`);

--
-- Indexes for table `delivery_pricing_tiers`
--
ALTER TABLE `delivery_pricing_tiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_deliverytiers_company` (`company_id`);

--
-- Indexes for table `egg_production`
--
ALTER TABLE `egg_production`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_eggproduction_batch` (`batch_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_expenses_company` (`company_id`),
  ADD KEY `fk_expenses_batch` (`batch_id`);

--
-- Indexes for table `feed_consumption`
--
ALTER TABLE `feed_consumption`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_feedconsumption_batch` (`batch_id`);

--
-- Indexes for table `feed_purchases`
--
ALTER TABLE `feed_purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_feedpurchases_company` (`company_id`),
  ADD KEY `fk_feedpurchases_species` (`species_id`);

--
-- Indexes for table `feed_types`
--
ALTER TABLE `feed_types`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_feedtypes_species` (`species_id`);

--
-- Indexes for table `health_records`
--
ALTER TABLE `health_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_health_batch` (`batch_id`),
  ADD KEY `fk_health_vet` (`vet_user_id`);

--
-- Indexes for table `income`
--
ALTER TABLE `income`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_income_company` (`company_id`),
  ADD KEY `fk_income_batch` (`batch_id`);

--
-- Indexes for table `incubation_batches`
--
ALTER TABLE `incubation_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_incubation_company` (`company_id`),
  ADD KEY `fk_incubation_species` (`species_id`),
  ADD KEY `fk_incubation_sourcebatch` (`source_batch_id`);

--
-- Indexes for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  ADD KEY `idx_mhp_model` (`model_id`,`model_type`);

--
-- Indexes for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  ADD KEY `idx_mhr_model` (`model_id`,`model_type`);

--
-- Indexes for table `mortality_log`
--
ALTER TABLE `mortality_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mortality_batch` (`batch_id`);

--
-- Indexes for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notiflogs_company` (`company_id`),
  ADD KEY `fk_notiflogs_user` (`user_id`);

--
-- Indexes for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_id` (`company_id`);

--
-- Indexes for table `otp_codes`
--
ALTER TABLE `otp_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_otp_email_code` (`email`,`code`);

--
-- Indexes for table `payment_settings`
--
ALTER TABLE `payment_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_id` (`company_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_permissions` (`name`,`guard_name`);

--
-- Indexes for table `pincodes`
--
ALTER TABLE `pincodes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pincode` (`pincode`);

--
-- Indexes for table `refund_policies`
--
ALTER TABLE `refund_policies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_id` (`company_id`);

--
-- Indexes for table `refund_requests`
--
ALTER TABLE `refund_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_refundrequests_booking` (`booking_id`),
  ADD KEY `fk_refundrequests_user` (`resolved_by_user_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles` (`company_id`,`name`,`guard_name`);

--
-- Indexes for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`role_id`),
  ADD KEY `fk_rhp_role` (`role_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rooms_company` (`company_id`),
  ADD KEY `fk_rooms_species` (`current_species_id`);

--
-- Indexes for table `species`
--
ALTER TABLE `species`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_species_company` (`company_id`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_staff_user` (`user_id`),
  ADD KEY `idx_staff_company` (`company_id`,`status`);

--
-- Indexes for table `staff_adjustments`
--
ALTER TABLE `staff_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_adjustments_staff_month` (`staff_id`,`month`),
  ADD KEY `fk_adjustments_company` (`company_id`);

--
-- Indexes for table `staff_advances`
--
ALTER TABLE `staff_advances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_advances_staff` (`staff_id`,`given_on`),
  ADD KEY `fk_advances_company` (`company_id`);

--
-- Indexes for table `staff_advance_recoveries`
--
ALTER TABLE `staff_advance_recoveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_recoveries_payroll` (`payroll_id`),
  ADD KEY `fk_recoveries_advance` (`advance_id`);

--
-- Indexes for table `staff_attendance`
--
ALTER TABLE `staff_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_staff_day` (`staff_id`,`date`),
  ADD KEY `idx_attendance_company_date` (`company_id`,`date`);

--
-- Indexes for table `staff_employment_periods`
--
ALTER TABLE `staff_employment_periods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_periods_staff` (`staff_id`,`started_on`),
  ADD KEY `fk_periods_company` (`company_id`);

--
-- Indexes for table `staff_payroll`
--
ALTER TABLE `staff_payroll`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_payroll_staff_month` (`staff_id`,`month`),
  ADD KEY `idx_payroll_company_month` (`company_id`,`month`);

--
-- Indexes for table `storefront_listings`
--
ALTER TABLE `storefront_listings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_storefront_company_slug` (`company_id`,`slug`),
  ADD KEY `fk_storefront_batch` (`batch_id`);

--
-- Indexes for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_supplier_update_token` (`update_token`),
  ADD KEY `fk_suppliers_company` (`company_id`);

--
-- Indexes for table `supplier_species`
--
ALTER TABLE `supplier_species`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_supplier_species_species` (`species_id`),
  ADD KEY `idx_supplier_species_lookup` (`supplier_id`,`species_id`,`breed_id`,`age_days`),
  ADD KEY `fk_supplier_species_breed` (`breed_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_company` (`company_id`);

--
-- Indexes for table `user_devices`
--
ALTER TABLE `user_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `fk_user_devices_user` (`user_id`);

--
-- Indexes for table `user_notes`
--
ALTER TABLE `user_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_notes_user` (`user_id`,`status`,`remind_on`),
  ADD KEY `idx_user_notes_due` (`status`,`remind_on`),
  ADD KEY `fk_user_notes_company` (`company_id`),
  ADD KEY `idx_user_notes_shared` (`company_id`,`is_shared`,`status`);

--
-- Indexes for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_vaccrecords_batch` (`batch_id`),
  ADD KEY `fk_vaccrecords_schedule` (`vaccination_schedule_id`),
  ADD KEY `fk_vaccrecords_user` (`given_by_user_id`);

--
-- Indexes for table `vaccination_schedules`
--
ALTER TABLE `vaccination_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_vaccschedules_species` (`species_id`);

--
-- Indexes for table `weight_logs`
--
ALTER TABLE `weight_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_weightlogs_batch` (`batch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alerts_log`
--
ALTER TABLE `alerts_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `backup_events`
--
ALTER TABLE `backup_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `booking_items`
--
ALTER TABLE `booking_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `breeds`
--
ALTER TABLE `breeds`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_subscriptions`
--
ALTER TABLE `company_subscriptions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_notes`
--
ALTER TABLE `contact_notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_species`
--
ALTER TABLE `customer_species`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_pricing_tiers`
--
ALTER TABLE `delivery_pricing_tiers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `egg_production`
--
ALTER TABLE `egg_production`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feed_consumption`
--
ALTER TABLE `feed_consumption`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feed_purchases`
--
ALTER TABLE `feed_purchases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feed_types`
--
ALTER TABLE `feed_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `health_records`
--
ALTER TABLE `health_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `income`
--
ALTER TABLE `income`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `incubation_batches`
--
ALTER TABLE `incubation_batches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mortality_log`
--
ALTER TABLE `mortality_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_logs`
--
ALTER TABLE `notification_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_settings`
--
ALTER TABLE `notification_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otp_codes`
--
ALTER TABLE `otp_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_settings`
--
ALTER TABLE `payment_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pincodes`
--
ALTER TABLE `pincodes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `refund_policies`
--
ALTER TABLE `refund_policies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `refund_requests`
--
ALTER TABLE `refund_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `species`
--
ALTER TABLE `species`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_adjustments`
--
ALTER TABLE `staff_adjustments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_advances`
--
ALTER TABLE `staff_advances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_advance_recoveries`
--
ALTER TABLE `staff_advance_recoveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_attendance`
--
ALTER TABLE `staff_attendance`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_employment_periods`
--
ALTER TABLE `staff_employment_periods`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_payroll`
--
ALTER TABLE `staff_payroll`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storefront_listings`
--
ALTER TABLE `storefront_listings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subscription_plans`
--
ALTER TABLE `subscription_plans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_species`
--
ALTER TABLE `supplier_species`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_devices`
--
ALTER TABLE `user_devices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_notes`
--
ALTER TABLE `user_notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vaccination_schedules`
--
ALTER TABLE `vaccination_schedules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weight_logs`
--
ALTER TABLE `weight_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alerts_log`
--
ALTER TABLE `alerts_log`
  ADD CONSTRAINT `fk_alertslog_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `batches`
--
ALTER TABLE `batches`
  ADD CONSTRAINT `fk_batches_breed` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_batches_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_batches_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_batches_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`);

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `booking_items`
--
ALTER TABLE `booking_items`
  ADD CONSTRAINT `fk_bookingitems_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bookingitems_listing` FOREIGN KEY (`storefront_listing_id`) REFERENCES `storefront_listings` (`id`);

--
-- Constraints for table `breeds`
--
ALTER TABLE `breeds`
  ADD CONSTRAINT `fk_breeds_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_breeds_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `company_subscriptions`
--
ALTER TABLE `company_subscriptions`
  ADD CONSTRAINT `fk_company_subscriptions_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_company_subscriptions_plan` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`id`);

--
-- Constraints for table `contact_notes`
--
ALTER TABLE `contact_notes`
  ADD CONSTRAINT `fk_contact_notes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_contact_notes_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customer_species`
--
ALTER TABLE `customer_species`
  ADD CONSTRAINT `fk_customer_species_breed` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_customer_species_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_customer_species_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_pricing_tiers`
--
ALTER TABLE `delivery_pricing_tiers`
  ADD CONSTRAINT `fk_deliverytiers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `egg_production`
--
ALTER TABLE `egg_production`
  ADD CONSTRAINT `fk_eggproduction_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expenses_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_expenses_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feed_consumption`
--
ALTER TABLE `feed_consumption`
  ADD CONSTRAINT `fk_feedconsumption_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feed_purchases`
--
ALTER TABLE `feed_purchases`
  ADD CONSTRAINT `fk_feedpurchases_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_feedpurchases_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `feed_types`
--
ALTER TABLE `feed_types`
  ADD CONSTRAINT `fk_feedtypes_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `health_records`
--
ALTER TABLE `health_records`
  ADD CONSTRAINT `fk_health_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_health_vet` FOREIGN KEY (`vet_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `income`
--
ALTER TABLE `income`
  ADD CONSTRAINT `fk_income_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_income_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `incubation_batches`
--
ALTER TABLE `incubation_batches`
  ADD CONSTRAINT `fk_incubation_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_incubation_sourcebatch` FOREIGN KEY (`source_batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_incubation_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`);

--
-- Constraints for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD CONSTRAINT `fk_mhp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `fk_mhr_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mortality_log`
--
ALTER TABLE `mortality_log`
  ADD CONSTRAINT `fk_mortality_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD CONSTRAINT `fk_notiflogs_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notiflogs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD CONSTRAINT `fk_notification_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_settings`
--
ALTER TABLE `payment_settings`
  ADD CONSTRAINT `fk_payment_settings_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `refund_policies`
--
ALTER TABLE `refund_policies`
  ADD CONSTRAINT `fk_refundpolicies_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `refund_requests`
--
ALTER TABLE `refund_requests`
  ADD CONSTRAINT `fk_refundrequests_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_refundrequests_user` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `fk_roles_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD CONSTRAINT `fk_rhp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rhp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rooms`
--
ALTER TABLE `rooms`
  ADD CONSTRAINT `fk_rooms_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rooms_species` FOREIGN KEY (`current_species_id`) REFERENCES `species` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `species`
--
ALTER TABLE `species`
  ADD CONSTRAINT `fk_species_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff`
--
ALTER TABLE `staff`
  ADD CONSTRAINT `fk_staff_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_staff_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `staff_adjustments`
--
ALTER TABLE `staff_adjustments`
  ADD CONSTRAINT `fk_adjustments_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_adjustments_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_advances`
--
ALTER TABLE `staff_advances`
  ADD CONSTRAINT `fk_advances_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_advances_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_advance_recoveries`
--
ALTER TABLE `staff_advance_recoveries`
  ADD CONSTRAINT `fk_recoveries_advance` FOREIGN KEY (`advance_id`) REFERENCES `staff_advances` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_recoveries_payroll` FOREIGN KEY (`payroll_id`) REFERENCES `staff_payroll` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_attendance`
--
ALTER TABLE `staff_attendance`
  ADD CONSTRAINT `fk_attendance_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attendance_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_employment_periods`
--
ALTER TABLE `staff_employment_periods`
  ADD CONSTRAINT `fk_periods_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_periods_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_payroll`
--
ALTER TABLE `staff_payroll`
  ADD CONSTRAINT `fk_payroll_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payroll_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `storefront_listings`
--
ALTER TABLE `storefront_listings`
  ADD CONSTRAINT `fk_storefront_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_storefront_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `fk_suppliers_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_species`
--
ALTER TABLE `supplier_species`
  ADD CONSTRAINT `fk_supplier_species_breed` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_supplier_species_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_supplier_species_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_devices`
--
ALTER TABLE `user_devices`
  ADD CONSTRAINT `fk_user_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_notes`
--
ALTER TABLE `user_notes`
  ADD CONSTRAINT `fk_user_notes_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vaccination_records`
--
ALTER TABLE `vaccination_records`
  ADD CONSTRAINT `fk_vaccrecords_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vaccrecords_schedule` FOREIGN KEY (`vaccination_schedule_id`) REFERENCES `vaccination_schedules` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vaccrecords_user` FOREIGN KEY (`given_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vaccination_schedules`
--
ALTER TABLE `vaccination_schedules`
  ADD CONSTRAINT `fk_vaccschedules_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `weight_logs`
--
ALTER TABLE `weight_logs`
  ADD CONSTRAINT `fk_weightlogs_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
