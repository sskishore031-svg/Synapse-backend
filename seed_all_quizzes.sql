-- Seed subject-specific quiz questions for all 30 chapters in synapse_ai
USE synapse_ai;

INSERT INTO chapter_quizzes (chapter_id, subject_id, question, option_a, option_b, option_c, option_d, correct_option, explanation)
VALUES
-- Chapter 1: Mathematics - Quadratic Equations
(1, 1, 'What is the standard algebraic form of a quadratic equation?', 'ax + b = 0', 'ax² + bx + c = 0', 'ax³ + bx² + c = 0', 'y = mx + b', 'B', 'A quadratic equation in standard form is written as ax² + bx + c = 0 where a ≠ 0.'),
(1, 1, 'What are the roots of the quadratic equation x² - 5x + 6 = 0?', 'x = 1, 6', 'x = 2, 3', 'x = -2, -3', 'x = 0, 5', 'B', 'Factoring x² - 5x + 6 = 0 gives (x - 2)(x - 3) = 0, yielding x = 2 and x = 3.'),
(1, 1, 'If the discriminant Δ = b² - 4ac is less than zero, what is the nature of the roots?', 'Two distinct real roots', 'One repeated real root', 'Two complex conjugate roots', 'No roots exist in any number system', 'C', 'A negative discriminant produces square roots of negative numbers, resulting in complex conjugate roots.'),

-- Chapter 2: Mathematics - Differential Calculus
(2, 1, 'What does the derivative f\'(x) represent geometrically on the graph of f(x)?', 'Area under the curve', 'Slope of the tangent line', 'Y-intercept of the curve', 'Length of the arc', 'B', 'The derivative evaluated at x represents the instantaneous slope of the tangent line at that point.'),
(2, 1, 'What is the derivative of f(x) = 4x³ + 7x with respect to x?', '12x² + 7', '12x³ + 7', '7x² + 4', '4x² + 7x', 'A', 'Using the power rule d/dx(x^n) = n*x^(n-1), d/dx(4x³) = 12x² and d/dx(7x) = 7.'),
(2, 1, 'Which differentiation rule is used to differentiate composite functions like f(g(x))?', 'Product rule', 'Quotient rule', 'Chain rule', 'Power rule', 'C', 'The chain rule computes d/dx[f(g(x))] = f\'(g(x)) * g\'(x).'),

-- Chapter 3: Mathematics - Linear Algebra & Matrices
(3, 1, 'Under what condition can two matrices A and B be multiplied together (A * B)?', 'Same number of total elements', 'Columns of A must equal rows of B', 'Both matrices must be square', 'Determinants must be equal', 'B', 'Matrix multiplication requires the inner dimensions to match: columns of first matrix must equal rows of second matrix.'),
(3, 1, 'What is the determinant of the 2x2 matrix [[3, 2], [1, 4]]?', '10', '14', '12', '7', 'A', 'For 2x2 matrix [[a, b], [c, d]], det = ad - bc = (3 * 4) - (2 * 1) = 12 - 2 = 10.'),
(3, 1, 'If a square matrix has a determinant of 0, what does it signify?', 'It is the identity matrix', 'It is non-invertible (singular)', 'It has only positive eigenvalues', 'It is orthogonal', 'B', 'A zero determinant indicates linear dependence between rows/columns, meaning the matrix has no inverse.'),

-- Chapter 4: Mathematics - Integral Calculus
(4, 1, 'What is the indefinite integral ∫ (3x² + 2) dx?', '6x + C', 'x³ + 2x + C', '3x³ + 2x + C', 'x³ + C', 'B', 'Integrating 3x² gives x³, and integrating 2 gives 2x, plus the arbitrary constant C.'),
(4, 1, 'What does the Fundamental Theorem of Calculus state for ∫[a to b] f(x) dx?', 'f(b) - f(a)', 'F(b) - F(a) where F\'(x) = f(x)', 'F(b) * F(a)', 'f\'(b) - f\'(a)', 'B', 'The definite integral evaluates to F(b) - F(a), where F is the antiderivative of f.'),
(4, 1, 'What technique is used to evaluate integrals of the form ∫ u dv?', 'Partial fractions', 'Integration by parts (uv - ∫ v du)', 'Trigonometric substitution', 'L\'Hôpital\'s Rule', 'B', 'Integration by parts derives from the product rule: ∫ u dv = uv - ∫ v du.'),

-- Chapter 5: Mathematics - Probability & Statistics
(5, 1, 'What is the probability of rolling an odd number on a fair six-sided die?', '1/6', '1/3', '1/2', '2/3', 'C', 'Odd numbers are {1, 3, 5}, which is 3 outcomes out of 6 total possibilities (3/6 = 1/2).'),
(5, 1, 'What does Bayes\' Theorem calculate?', 'P(A ∩ B)', 'P(A ∪ B)', 'Posterior probability P(A|B) using likelihood and prior', 'Sample variance', 'C', 'Bayes\' Theorem computes conditional probability P(A|B) = [P(B|A) * P(A)] / P(B).'),
(5, 1, 'In a standard normal distribution, what are the mean and standard deviation?', 'Mean = 1, SD = 1', 'Mean = 0, SD = 1', 'Mean = 0, SD = 0', 'Mean = 100, SD = 15', 'B', 'A standard normal z-distribution has mean μ = 0 and standard deviation σ = 1.'),

-- Chapter 6: Computer Science - Introduction to Algorithms
(6, 2, 'What does Big O notation describe in algorithm analysis?', 'Exact execution time in milliseconds', 'Upper bound on growth rate as input size increases', 'Minimum memory required by CPU cache', 'Number of code lines in program', 'B', 'Big O notation characterizes the asymptotic upper bound on runtime or space as n approaches infinity.'),
(6, 2, 'Which of the following time complexities represents the fastest scaling growth rate?', 'O(n²)', 'O(n log n)', 'O(1)', 'O(n)', 'C', 'O(1) constant time does not grow with input size, making it the most efficient.'),
(6, 2, 'What are the three steps in the Divide and Conquer algorithmic paradigm?', 'Loop, Branch, Terminate', 'Divide, Conquer, Combine', 'Allocate, Compute, Deallocate', 'Sort, Filter, Map', 'B', 'Divide into subproblems, conquer recursively, and combine subproblem solutions.'),

-- Chapter 7: Computer Science - Data Structures & Arrays
(7, 2, 'What is the time complexity of accessing an element by index in a static array?', 'O(1)', 'O(log n)', 'O(n)', 'O(n²)', 'A', 'Arrays occupy contiguous memory, enabling immediate pointer arithmetic offset calculation in O(1) time.'),
(7, 2, 'Why does inserting an element at the beginning of an array take O(n) time?', 'Memory must be reallocated', 'All existing elements must be shifted right by one position', 'Array must be sorted first', 'Indices are rehashed', 'B', 'Every existing element from index 0 to n-1 must be moved one slot to the right to open index 0.'),
(7, 2, 'What is the primary advantage of a linked list over a static array?', 'Faster random access by index', 'Constant-time O(1) insertion at head without pre-allocated capacity', 'Better CPU cache locality', 'Less total memory consumption per element', 'B', 'Linked lists allocate nodes dynamically and can insert at the head in O(1) time without shifting.'),

-- Chapter 8: Computer Science - Sorting & Searching Algorithms
(8, 2, 'What is the prerequisite for applying Binary Search on an array?', 'Elements must be unique', 'Array must be sorted', 'Array size must be a power of 2', 'Elements must be positive integers', 'B', 'Binary search requires sorted data so comparing with the middle element can eliminate half the search space.'),
(8, 2, 'What is the guaranteed worst-case time complexity of Merge Sort?', 'O(n)', 'O(n log n)', 'O(n²)', 'O(2^n)', 'B', 'Merge sort splits and merges logarithmically, maintaining O(n log n) in best, average, and worst cases.'),
(8, 2, 'What is the worst-case time complexity of Quick Sort with poor pivot selection?', 'O(log n)', 'O(n log n)', 'O(n²)', 'O(n!)', 'C', 'If the pivot is always the extreme element on already-sorted data, Quick Sort degrades to O(n²).'),

-- Chapter 9: Computer Science - Graph Theory & Trees
(9, 2, 'Which traversal of a Binary Search Tree (BST) visits nodes in non-decreasing sorted order?', 'Pre-order traversal', 'In-order traversal', 'Post-order traversal', 'Level-order traversal', 'B', 'In-order traversal visits Left Subtree -> Root -> Right Subtree, producing ascending order in BSTs.'),
(9, 2, 'Which data structure is fundamentally used to implement Breadth-First Search (BFS)?', 'Stack', 'Queue', 'Priority Queue', 'Disjoint Set', 'B', 'BFS explores level by level using a FIFO Queue to process discovered nodes in order of distance.'),
(9, 2, 'What distinguishes a tree from a general graph?', 'Trees cannot contain vertices', 'A tree is a connected graph with no cycles', 'Trees have weighted edges only', 'Graphs must be bipartite', 'B', 'A tree with n vertices is connected and contains exactly n - 1 edges with no closed loops (cycles).'),

-- Chapter 10: Computer Science - Dynamic Programming
(10, 2, 'What two core properties must a problem exhibit to be solvable by Dynamic Programming?', 'Linearity and Symmetry', 'Optimal Substructure and Overlapping Subproblems', 'Greedy Choice and Binary Partitions', 'Recursion and Randomization', 'B', 'Optimal substructure allows building solutions from subproblem solutions; overlapping subproblems makes caching useful.'),
(10, 2, 'What is the difference between Memoization and Tabulation in Dynamic Programming?', 'Memoization is bottom-up; Tabulation is top-down', 'Memoization is top-down recursive caching; Tabulation is bottom-up iterative table filling', 'Memoization uses more time than tabulation', 'Tabulation does not store intermediate results', 'B', 'Memoization caches recursive results on demand; Tabulation builds from base cases iteratively.'),
(10, 2, 'What is the time complexity of the 0/1 Knapsack problem with n items and capacity W using DP?', 'O(2^n)', 'O(n * W)', 'O(n + W)', 'O(n log W)', 'B', 'The dynamic programming solution fills an n x W table, resulting in pseudo-polynomial O(n * W) time.'),

-- Chapter 11: Data Science - Exploratory Data Analysis
(11, 3, 'When imputing missing values in a heavily skewed numerical feature, which metric is preferred?', 'Mean', 'Median', 'Standard Deviation', 'Variance', 'B', 'The median is robust against extreme outlier values, whereas the mean is pulled heavily by skewness.'),
(11, 3, 'What statistical measure defines the Interquartile Range (IQR)?', 'Max - Min', 'Q3 (75th percentile) - Q1 (25th percentile)', 'Mean - Median', 'Standard Deviation / Mean', 'B', 'IQR measures the spread of the middle 50% of the distribution (Q3 - Q1).'),
(11, 3, 'In a standard boxplot, data points beyond 1.5 * IQR from quartiles are considered what?', 'Missing values', 'Outliers', 'Modes', 'Variance thresholds', 'B', 'Tukey\'s rule flags observations beyond Q1 - 1.5*IQR or Q3 + 1.5*IQR as potential outliers.'),

-- Chapter 12: Data Science - Data Visualization
(12, 3, 'Which visualization is most suitable for inspecting the correlation between two continuous variables?', 'Pie chart', 'Scatter plot', 'Stacked bar chart', 'Treemap', 'B', 'Scatter plots display pairs of numerical coordinates to reveal linear or non-linear correlation patterns.'),
(12, 3, 'What does a Pearson correlation coefficient of -0.92 indicate?', 'No relationship', 'Weak positive correlation', 'Strong negative linear relationship', 'Non-linear independence', 'C', 'Values close to -1 indicate that as one variable increases, the other variable decreases proportionally.'),
(12, 3, 'Why are heatmaps effective for exploratory multivariate data analysis?', 'They eliminate all outliers', 'Color gradients highlight dense correlations across large feature matrices instantly', 'They convert continuous variables into binary integers', 'They guarantee zero error in regression models', 'B', 'Heatmaps map numeric matrices to colored grids, making strong positive and negative correlations obvious.'),

-- Chapter 13: Data Science - Statistical Inference
(13, 3, 'If an experiment yields a p-value of 0.015 at significance level α = 0.05, what is the conclusion?', 'Fail to reject the null hypothesis', 'Reject the null hypothesis; result is statistically significant', 'Accept the null hypothesis as proven truth', 'Increase significance level to 0.10', 'B', 'Because p-value (0.015) < α (0.05), we reject the null hypothesis in favor of the alternative hypothesis.'),
(13, 3, 'What does the Central Limit Theorem state about sample means as sample size n becomes large?', 'Sample means approach a Uniform distribution', 'Sample means approach a Normal distribution regardless of population shape', 'Standard error increases linearly with n', 'Population variance becomes zero', 'B', 'For sufficiently large sample sizes (typically n ≥ 30), the distribution of sample means approaches normality.'),
(13, 3, 'What does a 95% Confidence Interval for a population mean convey?', '95% of individual data points fall in this range', 'If sampled repeatedly, 95% of such calculated intervals would contain the true population mean', 'The sample mean has a 95% chance of being wrong', 'The population variance is exactly 0.95', 'B', 'Frequentist confidence intervals describe long-run capture rates across repeated random sampling.'),

-- Chapter 14: Data Science - Linear & Logistic Regression
(14, 3, 'What does an R² (coefficient of determination) value of 0.85 signify in linear regression?', '85% of predictions are false positives', '85% of variance in the dependent variable is explained by the model features', 'Residual sum of squares equals 0.85', 'The slope of regression line is 0.85', 'B', 'R² represents the proportion of variance in Y captured by the linear relationship with predictors X.'),
(14, 3, 'What is the primary purpose of the sigmoid function σ(z) in logistic regression?', 'Compute square roots of errors', 'Map any real-valued number into a probability between 0 and 1', 'Perform principal component rotation', 'Normalize feature standard deviations', 'B', 'The sigmoid function σ(z) = 1 / (1 + e^-z) maps unbounded linear outputs into valid probabilities [0, 1].'),
(14, 3, 'Which loss function is minimized during the training of a binary logistic regression model?', 'Mean Squared Error (MSE)', 'Binary Cross-Entropy (Log-Loss)', 'Hinge Loss', 'Huber Loss', 'B', 'Binary Cross-Entropy penalizes confident incorrect probabilistic predictions exponentially.'),

-- Chapter 15: Data Science - Feature Engineering
(15, 3, 'Why is feature scaling essential before training algorithms like K-Nearest Neighbors (KNN) or SVM?', 'Algorithms will fail to compile without scaled floats', 'Distance-based metrics are otherwise dominated by features with large absolute ranges', 'Scaling converts categorical columns to integers', 'It eliminates all missing rows automatically', 'B', 'Euclidean distance calculations are distorted when one feature is measured in thousands and another in decimals.'),
(15, 3, 'What technique converts nominal categorical variables into binary indicator columns (0 or 1)?', 'Standardization', 'One-Hot Encoding', 'Principal Component Analysis', 'Min-Max Normalization', 'B', 'One-Hot Encoding creates a dedicated binary column for each category level without imposing false ordinal hierarchy.'),
(15, 3, 'What is the primary objective of Principal Component Analysis (PCA)?', 'Increase number of features', 'Project data onto orthogonal axes of maximum variance to reduce dimensionality', 'Classify binary target labels', 'Impute missing values using regression', 'B', 'PCA identifies uncorrelated principal components that preserve the greatest variance in fewer dimensions.'),

-- Chapter 16: Artificial Intelligence - Introduction to AI
(16, 4, 'What does the PEAS acronym stand for in rational agent specification?', 'Planning, Execution, Action, Strategy', 'Performance measure, Environment, Actuators, Sensors', 'Perception, Energy, Algorithm, System', 'Processing, Estimation, Analysis, State', 'B', 'PEAS defines Performance measure, Environment, Actuators, and Sensors for agent task environments.'),
(16, 4, 'Under what condition is the A* search algorithm guaranteed to find the optimal path?', 'Heuristic h(n) is admissible (never overestimates true cost to goal)', 'Branching factor is infinite', 'Search space contains no cycles', 'State space has uniform step costs', 'A', 'Admissibility guarantees that A* never prematurely terminates with a sub-optimal path.'),
(16, 4, 'What is an environment called if the agent\'s sensors give access to the complete state of the environment at all times?', 'Partially observable', 'Fully observable', 'Stochastic', 'Continuous', 'B', 'In fully observable environments, complete state information is available without uncertainty.'),

-- Chapter 17: Artificial Intelligence - Neural Network Basics
(17, 4, 'What mathematical operation does an artificial neuron perform before applying activation?', 'Computes the dot product of inputs and weights, then adds bias: z = W · X + b', 'Calculates the determinant of input matrix', 'Performs Fast Fourier Transform on inputs', 'Sorts input weights in descending order', 'A', 'Neurons calculate a linear combination of inputs with weights and bias before passing through non-linear activation.'),
(17, 4, 'Why must neural networks include non-linear activation functions?', 'To speed up floating point calculations', 'Without non-linearity, multi-layer networks collapse to a single linear transformation', 'To ensure weights are always positive', 'To prevent memory leaks in GPUs', 'B', 'Composition of purely linear layers is mathematically equivalent to a single linear layer; non-linearity enables universal approximation.'),
(17, 4, 'What is the output formula for the ReLU (Rectified Linear Unit) activation function?', 'f(z) = 1 / (1 + e^-z)', 'f(z) = max(0, z)', 'f(z) = tanh(z)', 'f(z) = z²', 'B', 'ReLU returns z if z > 0, and 0 otherwise, mitigating vanishing gradient issues.'),

-- Chapter 18: Artificial Intelligence - Gradient Descent
(18, 4, 'What is the main goal of Gradient Descent optimization?', 'Maximize total number of network weights', 'Iteratively update parameters to minimize the cost or loss function', 'Eliminate negative training samples', 'Make training loss oscillate indefinitely', 'B', 'Gradient Descent steps parameters opposite to the gradient to descend to the minimum of the loss surface.'),
(18, 4, 'What happens if the learning rate α is configured too high in gradient descent?', 'Training stops instantly at step 1', 'The algorithm may overshoot the minimum and diverge', 'Gradients vanish to exactly zero', 'The loss function becomes strictly convex', 'B', 'Excessive step sizes cause parameters to bounce across the valley walls and diverge away from the minimum.'),
(18, 4, 'How does Mini-Batch Gradient Descent differ from pure Batch Gradient Descent?', 'It calculates gradients using a small random subset of samples instead of the full dataset', 'It never updates network bias', 'It uses only 1 sample across the entire training epoch', 'It requires no learning rate parameter', 'A', 'Mini-batch utilizes batches (e.g. 32-256 samples) balancing vectorization efficiency with stochastic gradient noise.'),

-- Chapter 19: Artificial Intelligence - Backpropagation
(19, 4, 'Which fundamental calculus principle enables backpropagation in multi-layer networks?', 'Integration by parts', 'Chain rule of partial differentiation', 'Taylor series expansion', 'L\'Hôpital\'s Rule', 'B', 'The chain rule computes partial derivatives of loss with respect to early weights through intermediate layer activations.'),
(19, 4, 'What is the vanishing gradient problem in deep neural networks?', 'Gradients become excessively large and crash the program', 'Gradients shrink exponentially as they propagate backward through many layers, halting early layer updates', 'Weights are automatically set to zero by optimizer', 'Loss function ceases to have a gradient', 'B', 'Derivatives of activations like sigmoid (< 0.25) multiplied across many layers cause gradients to vanish to near zero.'),
(19, 4, 'Which technique helps prevent vanishing and exploding gradients in deep networks?', 'Increasing learning rate to 10.0', 'Batch Normalization and Residual Connections (Skip Connections)', 'Removing all hidden layers', 'Using only Sigmoid activation in all layers', 'B', 'Batch normalization standardizes layer inputs, and residual connections allow gradients to flow directly without attenuation.'),

-- Chapter 20: Artificial Intelligence - Model Evaluation & Metrics
(20, 4, 'In medical diagnostics where missing a malignant tumor has catastrophic consequences, which metric should be maximized?', 'Precision', 'Recall (Sensitivity)', 'Specificity', 'Accuracy alone', 'B', 'Recall minimizes False Negatives: Recall = TP / (TP + FN), ensuring as few cases as possible are missed.'),
(20, 4, 'What does an ROC-AUC score of 0.50 indicate for a binary classification model?', 'Perfect classification accuracy', 'Performance no better than random guessing', '100% false positive rate', 'Zero true positive predictions', 'B', 'An AUC of 0.5 corresponds to the diagonal chance line (random coin toss).'),
(20, 4, 'What is the F1-Score?', 'Arithmetic mean of accuracy and precision', 'Harmonic mean of Precision and Recall', 'Geometric mean of True Positives and False Positives', 'Square root of AUC', 'B', 'F1-Score = 2 * (Precision * Recall) / (Precision + Recall), balancing precision and recall on imbalanced data.'),

-- Chapter 21: Physics - Mechanical Waves
(21, 5, 'What distinguishes transverse waves from longitudinal waves?', 'Transverse waves require no energy', 'In transverse waves particle oscillation is perpendicular to propagation; in longitudinal waves it is parallel', 'Longitudinal waves cannot travel through solids', 'Transverse waves have no wavelength', 'B', 'Transverse oscillates perpendicular (e.g., guitar string, light); longitudinal oscillates parallel (e.g., sound in air).'),
(21, 5, 'If a wave has frequency f = 50 Hz and wavelength λ = 4 meters, what is its propagation speed v?', '12.5 m/s', '200 m/s', '54 m/s', '0.08 m/s', 'B', 'Using the fundamental wave equation v = f * λ: v = 50 * 4 = 200 m/s.'),
(21, 5, 'What phenomenon occurs when two waves overlap in the same medium?', 'Superposition (Interference)', 'Nuclear fusion', 'Total absorption of energy', 'Permanent destruction of frequency', 'A', 'The principle of superposition states that net displacement equals the algebraic sum of individual wave displacements.'),

-- Chapter 22: Physics - Optics & Wave Motion
(22, 5, 'What does Snell\'s Law describe in geometric optics?', 'Heat transfer across materials', 'Relationship between angles of incidence and refraction when light crosses media boundaries (n1 sin θ1 = n2 sin θ2)', 'Gravitational lensing around black holes', 'Speed of sound in water', 'B', 'Snell\'s law relates indices of refraction and angles of light bending at material interfaces.'),
(22, 5, 'Under what condition does Total Internal Reflection occur?', 'Light travels from denser to rarer medium and angle of incidence exceeds critical angle θc', 'Light travels from air into glass at 90 degrees', 'Light intensity is reduced to zero', 'Refractive index of second medium is infinite', 'A', 'Total internal reflection occurs when entering an optically rarer medium at angles exceeding the critical angle.'),
(22, 5, 'Which historic experiment decisively proved the wave nature of light?', 'Rutherford Gold Foil Experiment', 'Young\'s Double-Slit Experiment', 'Cavendish Torsion Balance Experiment', 'Millikan Oil Drop Experiment', 'B', 'Thomas Young demonstrated alternating constructive and destructive interference fringes characteristic of waves.'),

-- Chapter 23: Physics - Thermodynamics & Heat
(23, 5, 'What is the statement of the First Law of Thermodynamics?', 'Entropy always increases', 'Energy cannot be created or destroyed, only transformed (ΔU = Q - W)', 'Absolute zero temperature is unreachable', 'Heat flows spontaneously from cold to hot', 'B', 'The First Law expresses conservation of energy: change in internal energy equals heat added minus work done.'),
(23, 5, 'What physical concept is quantified by the Second Law of Thermodynamics?', 'Electric charge', 'Entropy (molecular disorder and dispersal of energy)', 'Gravitational constant', 'Inertial mass', 'B', 'The Second Law dictates that the total entropy of an isolated system never decreases over time.'),
(23, 5, 'What is the theoretical maximum thermal efficiency of an engine operating between 600 K and 300 K?', '100%', '75%', '50%', '25%', 'C', 'Carnot efficiency η_max = 1 - (T_cold / T_hot) = 1 - (300 / 600) = 0.50 or 50%.'),

-- Chapter 24: Physics - Electromagnetism
(24, 5, 'According to Coulomb\'s Law, what happens to the electrostatic force between two point charges if their distance is doubled?', 'Force doubles', 'Force quadruples', 'Force decreases to one-fourth (1/4)', 'Force remains unchanged', 'C', 'Coulomb\'s Law is an inverse-square law: F ∝ 1/r², so doubling distance reduces force by 2² = 4.'),
(24, 5, 'What does Faraday\'s Law of Induction state?', 'Electric charge is conserved in all circuits', 'A changing magnetic flux induces an electromotive force (EMF) in a closed circuit', 'Magnetic monopoles exist at atomic scales', 'Current creates resistance in conductors', 'B', 'Faraday\'s Law shows that time-varying magnetic flux induces EMF: EMF = -dΦB/dt.'),
(24, 5, 'What is the direction of the magnetic force on a positive charge moving parallel to a magnetic field?', 'Perpendicular to both velocity and field', 'Opposite to the velocity vector', 'Zero (no magnetic force)', 'Parallel to the magnetic field', 'C', 'The Lorentz force is F = q(v × B) = q*v*B*sin(θ). When velocity is parallel to field, θ = 0° and sin(0) = 0.'),

-- Chapter 25: Physics - Quantum Mechanics Basics
(25, 5, 'What formula relates the energy of a photon to its frequency according to Planck and Einstein?', 'E = mc²', 'E = h * f', 'E = 1/2 m v²', 'E = q * V', 'B', 'Photon energy is directly proportional to frequency via Planck\'s constant: E = h * f.'),
(25, 5, 'What does the Heisenberg Uncertainty Principle state?', 'Particles have zero kinetic energy at absolute zero', 'It is impossible to simultaneously measure the exact position and momentum of a particle with arbitrary precision', 'Speed of light depends on reference frame', 'Electrons possess negative spin only', 'B', 'Δx * Δp ≥ ℏ / 2 dictates a fundamental quantum limit to simultaneous precision of conjugate observables.'),
(25, 5, 'In quantum mechanics, what does the square of the wavefunction |ψ(x)|² represent?', 'Exact velocity of particle', 'Probability density of finding the particle at position x', 'Gravitational potential of particle', 'Electric field strength in vacuum', 'B', 'Born\'s probabilistic interpretation states that |ψ(x)|² gives the probability density of particle observation.'),

-- Chapter 26: Chemistry - Atomic Structure
(26, 6, 'Which subatomic particle determines the chemical identity (atomic number Z) of an element?', 'Neutron', 'Proton', 'Electron', 'Photon', 'B', 'The atomic number Z is defined strictly by the number of protons inside the atomic nucleus.'),
(26, 6, 'What are isotopes?', 'Atoms with same mass number but different atomic numbers', 'Atoms of the same element with the same number of protons but different numbers of neutrons', 'Molecules with identical molecular formulas but different geometric shapes', 'Ions with positive and negative charges bound together', 'B', 'Isotopes share the same atomic number Z (same protons) but differ in mass number A due to neutron counts.'),
(26, 6, 'What is the maximum number of electrons that can occupy a single atomic orbital according to Pauli Exclusion Principle?', '1', '2 with opposite spins', '6 with parallel spins', '8', 'B', 'The Pauli Exclusion Principle dictates that an orbital can hold at most two electrons, and they must have opposite spins.'),

-- Chapter 27: Chemistry - Periodic Table
(27, 6, 'What do elements belonging to the same vertical Group on the periodic table share in common?', 'Same atomic mass', 'Same number of valence electrons and similar chemical reactivity', 'Same number of electron shells', 'Identical boiling points', 'B', 'Elements in a group possess the same valence electron configuration, leading to similar chemical behavior.'),
(27, 6, 'Which periodic trend generally increases from left to right across a period and decreases down a group?', 'Atomic radius', 'Electronegativity and Ionization Energy', 'Metallic character', 'Atomic volume', 'B', 'Greater effective nuclear charge across a period draws valence electrons closer, increasing electronegativity and ionization energy.'),
(27, 6, 'Why are Noble Gases (Group 18) chemically unreactive under standard conditions?', 'They have zero protons', 'They possess complete valence electron octets (full valence shells)', 'They exist only as solid crystals', 'Their ionization energy is zero', 'B', 'A full valence shell (octet) represents a stable, low-energy electronic configuration that resists gaining or losing electrons.'),

-- Chapter 29: Chemistry - Acids and Bases
(29, 6, 'How does the Brønsted-Lowry theory define an acid and a base?', 'Acid: electron pair donor; Base: electron pair acceptor', 'Acid: proton (H+) donor; Base: proton (H+) acceptor', 'Acid: produces OH-; Base: produces H+', 'Acid: pH > 7; Base: pH < 7', 'B', 'Brønsted-Lowry theory defines acids as species that donate protons (H+), and bases as species that accept protons.'),
(29, 6, 'If a solution has a hydrogen ion concentration of [H+] = 10^-5 M, what is its pH?', '9', '5', '7', '14', 'B', 'pH is defined as -log10[H+] = -log10(10^-5) = 5 (which is acidic).'),
(29, 6, 'What is the function of a chemical buffer solution?', 'To neutralize all acids completely to water', 'To resist drastic changes in pH when small amounts of acid or base are introduced', 'To accelerate precipitation reactions', 'To increase electrical conductivity to maximum', 'B', 'Buffers contain a weak acid and its conjugate base (or vice-versa), buffering against hydrogen or hydroxide ion spikes.'),

-- Chapter 30: Chemistry - Chemical Reactions
(30, 6, 'What fundamental law requires chemical equations to be balanced with stoichiometric coefficients?', 'Law of Conservation of Energy', 'Law of Conservation of Mass', 'Boyle\'s Law', 'Avogadro\'s Law', 'B', 'The Law of Conservation of Mass dictates that atoms are neither created nor destroyed in chemical reactions; quantities on both sides must match.'),
(30, 6, 'What is the effect of adding a catalyst to a chemical reaction?', 'Increases overall heat of reaction (ΔH)', 'Lowers activation energy (Ea), speeding up reaction rate without being consumed', 'Shifts equilibrium to produce 100% products', 'Stops the reverse reaction entirely', 'B', 'Catalysts provide an alternative reaction pathway with lower activation energy, accelerating forward and reverse rates equally.'),
(30, 6, 'According to Le Chatelier\'s Principle, how will an exothermic reaction at equilibrium respond to an increase in temperature?', 'Shift in forward direction toward products', 'Shift in reverse direction toward reactants to absorb added heat', 'Equilibrium constant increases', 'Reaction ceases immediately', 'B', 'For exothermic reactions (which release heat), increasing temperature acts like adding product heat, shifting equilibrium left toward reactants.')

ON DUPLICATE KEY UPDATE 
question=VALUES(question),
option_a=VALUES(option_a),
option_b=VALUES(option_b),
option_c=VALUES(option_c),
option_d=VALUES(option_d),
correct_option=VALUES(correct_option),
explanation=VALUES(explanation);

