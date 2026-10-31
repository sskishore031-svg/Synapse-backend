-- ==============================================================================
-- Flashcards & AI Tutor Migration for Synapse AI
-- ==============================================================================

USE `synapse_ai`;

CREATE TABLE IF NOT EXISTS `flashcards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_id` INT NOT NULL,
    `chapter_id` INT DEFAULT NULL,
    `deck_name` VARCHAR(100) NOT NULL,
    `question` VARCHAR(500) NOT NULL,
    `answer` TEXT NOT NULL,
    `difficulty` VARCHAR(20) DEFAULT 'medium',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_subject` (`subject_id`),
    KEY `idx_chapter` (`chapter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_flashcard_progress` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `flashcard_id` INT NOT NULL,
    `is_difficult` TINYINT(1) DEFAULT 0,
    `is_reviewed` TINYINT(1) DEFAULT 0,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user_flashcard` (`user_id`, `flashcard_id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_tutor_chats` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `sender_type` ENUM('user', 'ai') NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_user_time` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed rich flashcards for subjects
INSERT IGNORE INTO `flashcards` (`id`, `subject_id`, `chapter_id`, `deck_name`, `question`, `answer`, `difficulty`) VALUES
(1, 4, 18, 'Gradient Descent', 'Why move in the direction opposite to the gradient?', 'The gradient vector points in the direction of steepest increase of the loss function. Moving in the opposite direction (-∇J) decreases the loss by the fastest rate.', 'medium'),
(2, 4, 18, 'Gradient Descent', 'What is the role of the learning rate (α)?', 'The learning rate is a hyperparameter that scales the magnitude of parameter updates. If too small, training is slow; if too large, the loss can diverge.', 'easy'),
(3, 4, 18, 'Gradient Descent', 'What is Stochastic Gradient Descent (SGD)?', 'SGD updates parameters using only a single training example per step instead of the entire dataset, introducing noisy updates that help escape local minima.', 'medium'),
(4, 4, 19, 'Neural Networks', 'What is an activation function?', 'A mathematical function applied to a neuron output that introduces non-linearity, enabling the network to learn complex non-linear patterns.', 'easy'),
(5, 4, 19, 'Neural Networks', 'Why is ReLU preferred over Sigmoid in hidden layers?', 'ReLU (max(0, x)) does not saturate for positive inputs, which mitigates the vanishing gradient problem and allows faster gradient descent convergence.', 'hard'),
(6, 1, 1, 'Quadratic Equations', 'What is the standard form of a quadratic equation?', 'ax² + bx + c = 0, where a, b, and c are real numbers and a ≠ 0.', 'easy'),
(7, 1, 1, 'Quadratic Equations', 'How does the discriminant (Δ = b² - 4ac) describe the roots?', 'If Δ > 0: two distinct real roots. If Δ = 0: two equal real roots. If Δ < 0: two complex conjugate roots.', 'medium'),
(8, 2, 6, 'Algorithms', 'What is the time complexity of Binary Search?', 'O(log n), because the search space is divided in half with every comparison on a sorted array.', 'easy'),
(9, 2, 6, 'Algorithms', 'Why is QuickSort average-case O(n log n) but worst-case O(n²)?', 'Average partitions split the array evenly (O(n log n)). Worst case occurs when the pivot is always the smallest or largest element, creating unbalanced trees of depth n.', 'hard'),
(10, 6, 28, 'Chemical Bonding', 'What is the primary difference between ionic and covalent bonds?', 'Ionic bonds involve the electrostatic attraction between oppositely charged ions formed by complete electron transfer; covalent bonds share electron pairs between non-metals.', 'medium'),
(11, 5, 21, 'Wave Mechanics', 'What is the relationship between wave speed, frequency, and wavelength?', 'v = f · λ, where v is wave speed in m/s, f is frequency in Hertz, and λ is wavelength in meters.', 'easy'),
(12, 3, 11, 'Data Science', 'What is the difference between supervised and unsupervised learning?', 'Supervised learning uses labeled training data to predict outcomes; unsupervised learning discovers patterns and clusters in unlabeled data.', 'easy');
