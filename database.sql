-- ملف قاعدة البيانات لوظيفة BWP501
-- نظام تسجيل الطلاب (Student Registration System)

CREATE DATABASE IF NOT EXISTS student_registration_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE student_registration_system;

-- جدول المستخدمين لتسجيل الدخول وإنشاء الحساب
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- جدول الطلاب
CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(15) NOT NULL,
    address TEXT,
    profile_picture VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- إضافة بيانات طلاب تجريبية لتجربة البحث وتعدد الصفحات (Pagination)
INSERT INTO students (name, email, phone, address, profile_picture) VALUES
('أحمد المنصور', 'ahmad.m@example.com', '0933112233', 'دمشق - المزة', ''),
('سارة قاسم', 'sara.k@example.com', '0944556677', 'حلب - الفرقان', ''),
('مازن إبراهيم', 'mazen.ibrahim@example.com', '0988776655', 'حمص - الإنشاءات', ''),
('لينا حداد', 'lina.haddad@example.com', '0991234567', 'اللاذقية - الزراعة', ''),
('طارق صالح', 'tareq.s@example.com', '0932114455', 'دمشق - الشعلان', ''),
('نور الدين العلي', 'nour.aldin@example.com', '0945667788', 'طرطوس - الكورنيش', ''),
('رامي خوري', 'rami.kh@example.com', '0955443322', 'دمشق - باب توما', ''),
('ديما يوسف', 'dima.y@example.com', '0966123123', 'حماة - ساحة العاصي', ''),
('خالد عمر', 'khaled.omar@example.com', '0933998877', 'حلب - العزيزية', ''),
('يارا حسن', 'yara.hassan@example.com', '0944112299', 'دمشق - المالكي', ''),
('فادي سليمان', 'fadi.s@example.com', '0988112233', 'اللاذقية - المشروع السابع', ''),
('رنا علي', 'rana.ali@example.com', '0999887766', 'حمص - عكرمة', '');
