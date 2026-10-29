USE synapse_ai;

-- Seed chapter progress for user 1
INSERT IGNORE INTO `user_chapter_progress` (`user_id`, `chapter_id`, `subject_id`, `is_completed`, `completed_at`) VALUES
-- Mathematics (chapters 1, 2, 3)
(1, 1, 1, 1, NOW() - INTERVAL 10 DAY),
(1, 2, 1, 1, NOW() - INTERVAL 8 DAY),
(1, 3, 1, 1, NOW() - INTERVAL 6 DAY),
-- Computer Science (chapters 6, 7, 8, 9)
(1, 6, 2, 1, NOW() - INTERVAL 9 DAY),
(1, 7, 2, 1, NOW() - INTERVAL 7 DAY),
(1, 8, 2, 1, NOW() - INTERVAL 5 DAY),
(1, 9, 2, 1, NOW() - INTERVAL 3 DAY),
-- Data Science (chapters 11, 12)
(1, 11, 3, 1, NOW() - INTERVAL 6 DAY),
(1, 12, 3, 1, NOW() - INTERVAL 4 DAY),
-- Artificial Intelligence (chapters 16, 17, 18)
(1, 16, 4, 1, NOW() - INTERVAL 5 DAY),
(1, 17, 4, 1, NOW() - INTERVAL 3 DAY),
(1, 18, 4, 1, NOW() - INTERVAL 1 DAY),
-- Physics (chapters 21, 22)
(1, 21, 5, 1, NOW() - INTERVAL 4 DAY),
(1, 22, 5, 1, NOW() - INTERVAL 2 DAY),
-- Chemistry (chapter 26)
(1, 26, 6, 1, NOW() - INTERVAL 2 DAY);

-- Seed quiz attempts for user 1
INSERT IGNORE INTO `user_quiz_attempts` (`user_id`, `subject_id`, `quiz_title`, `score_percentage`, `correct_answers`, `total_questions`, `time_taken_seconds`, `created_at`) VALUES
(1, 1, 'Mathematics - Quadratic Equations Quiz', 75, 8, 10, 240, NOW() - INTERVAL 7 DAY),
(1, 2, 'Computer Science - Algorithms & Big-O Quiz', 88, 9, 10, 195, NOW() - INTERVAL 4 DAY),
(1, 3, 'Data Science - EDA & Statistics Quiz', 76, 8, 10, 260, NOW() - INTERVAL 3 DAY),
(1, 4, 'Artificial Intelligence - Gradient Descent Quiz', 80, 8, 10, 272, NOW() - INTERVAL 1 DAY),
(1, 5, 'Physics - Mechanical Waves Quiz', 44, 4, 10, 310, NOW() - INTERVAL 2 DAY),
(1, 6, 'Chemistry - Chemical Bonding Quiz', 50, 5, 10, 290, NOW() - INTERVAL 2 DAY);

-- Seed learning activity history for user 1
INSERT IGNORE INTO `user_learning_activity` (`user_id`, `activity_type`, `title`, `subtitle`, `subject_name`, `created_at`) VALUES
(1, 'QUIZ_ATTEMPTED', 'Completed AI Gradient Descent Quiz', 'Scored 80% (8/10 correct)', 'Artificial Intelligence', NOW() - INTERVAL 1 DAY),
(1, 'CHAPTER_COMPLETED', 'Finished Lesson: Gradient Descent', 'Mastered local minima and learning rates', 'Artificial Intelligence', NOW() - INTERVAL 1 DAY),
(1, 'CHAPTER_COMPLETED', 'Finished Lesson: Optics & Wave Motion', 'Explored refraction and interference', 'Physics', NOW() - INTERVAL 2 DAY),
(1, 'QUIZ_ATTEMPTED', 'Completed Chemistry Bonding Quiz', 'Scored 50% · Needs revision', 'Chemistry', NOW() - INTERVAL 2 DAY);