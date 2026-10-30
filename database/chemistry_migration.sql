-- ==============================================================================
-- Synapse AI - Chemistry Backend Migration
-- Database: synapse_ai
-- Description: Sets up Chemistry chapters, lesson contents (Part 1 & 2),
-- and chapter quiz questions with full relational integrity.
-- ==============================================================================

USE `synapse_ai`;

-- 1. Update Chemistry Chapters (subject_id = 6) to match frontend milestones
UPDATE `chapters` SET `title` = 'Atomic Structure', `estimated_minutes` = 12 WHERE `id` = 26;
UPDATE `chapters` SET `title` = 'Periodic Table', `estimated_minutes` = 15 WHERE `id` = 27;
UPDATE `chapters` SET `title` = 'Chemical Bonding', `estimated_minutes` = 18 WHERE `id` = 28;
UPDATE `chapters` SET `title` = 'Acids and Bases', `estimated_minutes` = 15 WHERE `id` = 29;
UPDATE `chapters` SET `title` = 'Chemical Reactions', `estimated_minutes` = 20 WHERE `id` = 30;

-- 2. Lesson Contents Table (Supports Part 1 & Part 2 for each chapter)
CREATE TABLE IF NOT EXISTS `chapter_lessons` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chapter_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `part_number` INT NOT NULL DEFAULT 1,
  `subtitle_tag` VARCHAR(100) DEFAULT 'Part 1: Foundations',
  `headline` VARCHAR(255) NOT NULL,
  `concept_text` TEXT NOT NULL,
  `key_points` TEXT NOT NULL,
  `example_title` VARCHAR(255) DEFAULT 'Quick Example',
  `example_problem` TEXT DEFAULT NULL,
  `example_solution` TEXT DEFAULT NULL,
  `quick_example_text` TEXT DEFAULT NULL,
  `ai_recommendation` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_chapter_part` (`chapter_id`, `part_number`),
  INDEX `idx_cl_chapter` (`chapter_id`),
  INDEX `idx_cl_subject` (`subject_id`),
  CONSTRAINT `fk_cl_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cl_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Chapter Quizzes Table
CREATE TABLE IF NOT EXISTS `chapter_quizzes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `chapter_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `question` TEXT NOT NULL,
  `option_a` VARCHAR(255) NOT NULL,
  `option_b` VARCHAR(255) NOT NULL,
  `option_c` VARCHAR(255) NOT NULL,
  `option_d` VARCHAR(255) NOT NULL,
  `correct_option` CHAR(1) NOT NULL DEFAULT 'A',
  `explanation` TEXT DEFAULT NULL,
  INDEX `idx_cq_chapter` (`chapter_id`),
  INDEX `idx_cq_subject` (`subject_id`),
  CONSTRAINT `fk_cq_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `chapters` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cq_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Seed Chemical Bonding Lesson Content (Chapter ID 28)
-- Part 1: What is Chemical Bonding?
INSERT INTO `chapter_lessons` 
(`chapter_id`, `subject_id`, `part_number`, `subtitle_tag`, `headline`, `concept_text`, `key_points`, `example_title`, `example_problem`, `example_solution`, `quick_example_text`, `ai_recommendation`)
VALUES (
  28,
  6,
  1,
  'Part 1: Foundations',
  'What is Chemical Bonding?',
  'Chemical bonding is the force of attraction between atoms that helps them form molecules and compounds. Atoms bond to achieve a stable electron configuration.',
  '["Ionic bonds: transfer of electrons (metal + non-metal).", "Covalent bonds: sharing of electrons (non-metal + non-metal).", "Forms stable compounds and molecules."]',
  'Quick Example',
  'Sodium chloride (NaCl) forms by transfer of one electron from Na to Cl.',
  'Na⁺ + Cl⁻ → NaCl',
  NULL,
  NULL
)
ON DUPLICATE KEY UPDATE 
  `headline` = VALUES(`headline`),
  `concept_text` = VALUES(`concept_text`),
  `key_points` = VALUES(`key_points`),
  `example_problem` = VALUES(`example_problem`),
  `example_solution` = VALUES(`example_solution`);

-- Part 2: Going Deeper (Ionic vs Covalent Bonding)
INSERT INTO `chapter_lessons` 
(`chapter_id`, `subject_id`, `part_number`, `subtitle_tag`, `headline`, `concept_text`, `key_points`, `example_title`, `example_problem`, `example_solution`, `quick_example_text`, `ai_recommendation`)
VALUES (
  28,
  6,
  2,
  'Part 2: Going Deeper',
  'Ionic vs Covalent Bonding',
  'Atoms form chemical bonds to achieve a stable electron configuration. The two main types are ionic bonds (transfer) and covalent bonds (sharing).',
  '["Ionic bonding: transfer of electrons (metal + non-metal).", "Covalent bonding: sharing of electrons (non-metal + non-metal).", "Ionic bonds form lattice structures.", "Covalent bonds form molecules."]',
  'Quick Example',
  NULL,
  NULL,
  'What type of bond is in H₂O?\nAnswer: Covalent (electron sharing).',
  'Practice drawing Lewis structures for common compounds.'
)
ON DUPLICATE KEY UPDATE 
  `headline` = VALUES(`headline`),
  `concept_text` = VALUES(`concept_text`),
  `key_points` = VALUES(`key_points`),
  `quick_example_text` = VALUES(`quick_example_text`),
  `ai_recommendation` = VALUES(`ai_recommendation`);

-- 5. Seed Chemical Bonding Quiz Questions
INSERT IGNORE INTO `chapter_quizzes` 
(`id`, `chapter_id`, `subject_id`, `question`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`, `explanation`)
VALUES
(1, 28, 6, 'Which type of chemical bond involves the complete transfer of electrons between atoms?', 'Ionic bond', 'Covalent bond', 'Metallic bond', 'Hydrogen bond', 'A', 'Ionic bonds are formed by electrostatic attraction following electron transfer between a metal and a non-metal.'),
(2, 28, 6, 'What type of bonding is present in a molecule of water (H₂O)?', 'Ionic bonding', 'Covalent bonding', 'Metallic bonding', 'Nuclear bonding', 'B', 'Oxygen and hydrogen atoms in H₂O share electrons, forming polar covalent bonds.'),
(3, 28, 6, 'Why do atoms bond to form molecules and compounds?', 'To increase atomic mass', 'To achieve a stable noble gas electron octet', 'To split into ions', 'To eliminate neutrons', 'B', 'Atoms bond to achieve low-energy stable valence electron configurations.');

