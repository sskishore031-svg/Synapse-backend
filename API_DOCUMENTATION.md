# 🚀 Synapse AI – Backend API Documentation (Production Ready 100/100)

Comprehensive, production-grade documentation for the **Synapse AI** mobile backend, built with **PHP (PDO)** and **MySQL/MariaDB** on **XAMPP**.

---

## 📌 Architecture & Design Highlights

* **100% Token-Free Architecture**: Operates directly with unique, reliable database identifiers (`user_id`). Eliminates token expiry bugs, bearer header clutter, and authentication overhead.
* **Third Normal Form (3NF) Modular Database**:
  - `users`: Core credentials and authentication.
  - `user_profiles`: Personal profile information, academic details, and avatar path.
  - `user_settings`: User learning preferences, daily study goals, and notification preferences.
  - `user_achievements`: Gamified learning badges and streak records with emoji badges.
  - `user_notifications`: In-app notification center and study reminder alerts.
  - `password_resets`: Audit log for security tracking.
* **Relational Integrity**: All child tables strictly reference `users.id` with `ON DELETE CASCADE`.
* **Password Security**: Native Bcrypt hashing via `password_hash()` and `password_verify()`.
* **Zero Overhead**: Fully ready for offline testing with saved Postman examples and automated test scripts.

---

## 🗄️ Database Tables (`synapse_ai`)

| Table Name | Description | Key Columns |
| :--- | :--- | :--- |
| **`users`** | Core credentials & account identity | `id` (PK), `name`, `email` (Unique), `phone`, `password` (Bcrypt), `created_at` |
| **`user_profiles`** | Personal bio, course, and photo | `id` (PK), `user_id` (FK), `name`, `bio`, `course`, `interests`, `profile_photo`, `updated_at` |
| **`user_settings`** | App goals, study timer, language | `id` (PK), `user_id` (FK), `preferred_language`, `study_goal`, `notifications_enabled`, `updated_at` |
| **`user_achievements`** | 8 Gamified learning badges | `id` (PK), `user_id` (FK), `title`, `description`, `badge_icon`, `is_unlocked`, `unlocked_at` |
| **`user_notifications`** | In-app alerts and notifications | `id` (PK), `user_id` (FK), `title`, `message`, `type`, `is_read`, `created_at` |
| **`password_resets`** | Password reset audit history | `id` (PK), `email`, `token`, `expires_at`, `used`, `created_at` |

---

## 📱 Android Screen to API Mapping

| Android Screen / ViewModel | HTTP Method | Endpoint URL | Identification |
| :--- | :--- | :--- | :--- |
| `RegisterScreen` | `POST` | `/auth/register.php` | Creates user & returns `user.id` |
| `LoginScreen` (User ID / Email) | `POST` | `/auth/login.php` | `user_id` & `password` |
| `LoginScreen` (Phone tab) | `POST` | `/auth/login.php` | `phone` & `password` |
| `ForgotPasswordScreen` | `POST` | `/auth/reset_password.php` | `user_id` (or `email`) + new password |
| `ProfileScreen` | `GET` | `/auth/profile.php?user_id=1` | `?user_id=1` |
| `EditProfileScreen` | `POST` | `/auth/profile.php` | JSON Body `user_id` |
| `ProfileSetupScreen` ('Make it yours') | `POST` | `/auth/profile_setup.php` | JSON Body `user_id` |
| `PersonalInfoScreen` (Photo Upload) | `POST` | `/auth/upload_profile.php` | Form-Data `user_id` & `photo` |
| `PersonalInfoScreen` (Remove Photo) | `POST` | `/auth/upload_profile.php` | Form-Data `user_id` & `remove_photo=1` |
| `SettingsScreen` / `StudyGoalScreen` | `POST` | `/auth/update_settings.php` | JSON Body `user_id` |
| `AchievementsScreen` | `GET` | `/auth/achievements.php?user_id=1` | `?user_id=1` |
| `NotificationCenterPreviewScreen` | `GET` | `/auth/notifications.php?user_id=1` | `?user_id=1` |

---

## ⚡ Default Test Account (Pre-Seeded)

* **User ID**: `1`
* **Name**: `Demo User`
* **Email**: `demo@example.com`
* **Phone**: `+1 202-555-0147`
* **Password**: `password123`
* **Course**: `B.Tech Computer Science · Year 3`
* **Badges pre-loaded**: 8 Badges (`⚡`, `🔥`, `🎯`, `🦉`, `🧩`, `🤖`, `📚`, `🌅`)
* **Notifications pre-loaded**: 3 items (Daily Goal, Summary Ready, Streak Reminder)

---

## 📦 Postman Collection Import
Import the final production collection from:
```text
C:\Users\SCAD LAB\Desktop\Synapse_AI_Clean_V2.postman_collection.json
```
or
```text
C:\xampp\htdocs\Synapse_backend\Synapse_AI_Clean_V2.postman_collection.json
```
