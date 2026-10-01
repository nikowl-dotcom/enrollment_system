<?php
/**
 * Teacher LMS - Quiz Action Handler (Phase 6)
 * Actions: create_quiz, edit_quiz, delete_quiz, toggle_publish (AJAX),
 *          add_question, edit_question, delete_question
 * Security: POST-only, CSRF, teacher ownership re-verified on every write.
 * Data: writes to lms_quizzes, lms_quiz_questions, lms_quiz_choices
 *       - same tables student LMS reads from; changes take immediate effect.
 */

require_once '../includes/lms_access.php';

$teacher = requireLmsTeacherAccess();
$userId  = $teacher['user_id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$action           = trim((string)($_POST['action'] ?? ''));
$sectionSubjectId = (int)filter_input(INPUT_POST, 'section_subject_id', FILTER_VALIDATE_INT);
$quizId           = (int)filter_input(INPUT_POST, 'quiz_id', FILTER_VALIDATE_INT);
$questionId       = (int)filter_input(INPUT_POST, 'question_id', FILTER_VALIDATE_INT);

$isAjax = ($action === 'toggle_publish');

$returnBase = $sectionSubjectId > 0
    ? resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId)
    : resolveAppUrl('teacher/lms_quizzes');

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please refresh and try again.']);
        exit;
    }
    $_SESSION['flash_error'] = 'Your session token expired. Please try again.';
    header('Location: ' . $returnBase);
    exit;
}

// Ownership check
$subject = null;
if ($sectionSubjectId > 0) {
    $subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
    if (!$subject) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Access denied: you are not assigned to that subject.']);
            exit;
        }
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms'));
        exit;
    }
}

// Helper: parse date time
function lmsTqParseDate(?string $raw): ?string {
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', trim($raw))
       ?: DateTime::createFromFormat('Y-m-d H:i', trim($raw))
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', trim($raw));
    if (!$dt) {
        throw new DomainException('Invalid date format.');
    }
    return $dt->format('Y-m-d H:i:s');
}

// Helper: parse score / points
function lmsTqParseScore(?string $raw, string $fieldName = 'Score', bool $nullable = true): ?float {
    if ($raw === null || trim($raw) === '') {
        if ($nullable) {
            return null;
        }
        throw new DomainException($fieldName . ' is required.');
    }
    $v = filter_var(trim($raw), FILTER_VALIDATE_FLOAT);
    if ($v === false || $v < 0) {
        throw new DomainException($fieldName . ' must be a positive number.');
    }
    return round($v, 2);
}

// Helper: verify quiz belongs to section_subject_id
function lmsTqVerifyQuiz(PDO $pdo, int $targetQuizId, int $targetSectionSubjectId): array {
    $stmt = $pdo->prepare('SELECT * FROM lms_quizzes WHERE id = :id AND section_subject_id = :ss LIMIT 1');
    $stmt->execute(['id' => $targetQuizId, 'ss' => $targetSectionSubjectId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) {
        throw new DomainException('Quiz not found or does not belong to this subject.');
    }
    return $quiz;
}

try {
    switch ($action) {

        /* ===== CREATE QUIZ ================================================= */
        case 'create_quiz': {
            if ($sectionSubjectId < 1) {
                throw new DomainException('Subject selection is required.');
            }
            $title        = trim((string)($_POST['title'] ?? ''));
            $instructions = trim((string)($_POST['instructions'] ?? ''));
            $timeLimit    = (int)filter_input(INPUT_POST, 'time_limit_minutes', FILTER_VALIDATE_INT);
            $attempts     = (int)filter_input(INPUT_POST, 'allowed_attempts', FILTER_VALIDATE_INT);
            $passingScore = lmsTqParseScore($_POST['passing_score'] ?? null, 'Passing score', true);
            $opensAt      = lmsTqParseDate($_POST['opens_at'] ?? null);
            $closesAt     = lmsTqParseDate($_POST['closes_at'] ?? null);
            $isPublished  = !empty($_POST['is_published']) ? 1 : 0;

            if ($title === '') {
                throw new DomainException('Quiz title is required.');
            }
            if ($timeLimit < 1) {
                throw new DomainException('Time limit must be at least 1 minute.');
            }
            if ($attempts < 1) {
                throw new DomainException('Allowed attempts must be at least 1.');
            }
            if ($opensAt && $closesAt && strtotime($closesAt) <= strtotime($opensAt)) {
                throw new DomainException('Closing date must be after opening date.');
            }

            // Auto-increment display_order
            $doStmt = $pdo->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM lms_quizzes WHERE section_subject_id = :ss');
            $doStmt->execute(['ss' => $sectionSubjectId]);
            $displayOrder = (int)$doStmt->fetchColumn();

            $insert = $pdo->prepare(
                'INSERT INTO lms_quizzes (section_subject_id, title, instructions, time_limit_minutes, allowed_attempts, passing_score, opens_at, closes_at, display_order, is_published, created_at)
                 VALUES (:ss, :title, :instructions, :time_limit, :attempts, :passing_score, :opens_at, :closes_at, :display_order, :published, NOW())'
            );
            $insert->execute([
                'ss'            => $sectionSubjectId,
                'title'         => $title,
                'instructions'  => $instructions !== '' ? $instructions : null,
                'time_limit'    => $timeLimit,
                'attempts'      => $attempts,
                'passing_score' => $passingScore,
                'opens_at'      => $opensAt,
                'closes_at'     => $closesAt,
                'display_order' => $displayOrder,
                'published'     => $isPublished,
            ]);

            $newQuizId = (int)$pdo->lastInsertId();
            $_SESSION['flash_success'] = 'Quiz created successfully! Now add questions to your quiz.';
            header('Location: ' . resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId . '&view_questions=' . $newQuizId));
            exit;
        }

        /* ===== EDIT QUIZ =================================================== */
        case 'edit_quiz': {
            if ($quizId < 1 || $sectionSubjectId < 1) {
                throw new DomainException('Invalid quiz request.');
            }
            lmsTqVerifyQuiz($pdo, $quizId, $sectionSubjectId);

            $title        = trim((string)($_POST['title'] ?? ''));
            $instructions = trim((string)($_POST['instructions'] ?? ''));
            $timeLimit    = (int)filter_input(INPUT_POST, 'time_limit_minutes', FILTER_VALIDATE_INT);
            $attempts     = (int)filter_input(INPUT_POST, 'allowed_attempts', FILTER_VALIDATE_INT);
            $passingScore = lmsTqParseScore($_POST['passing_score'] ?? null, 'Passing score', true);
            $opensAt      = lmsTqParseDate($_POST['opens_at'] ?? null);
            $closesAt     = lmsTqParseDate($_POST['closes_at'] ?? null);
            $isPublished  = !empty($_POST['is_published']) ? 1 : 0;

            if ($title === '') {
                throw new DomainException('Quiz title is required.');
            }
            if ($timeLimit < 1) {
                throw new DomainException('Time limit must be at least 1 minute.');
            }
            if ($attempts < 1) {
                throw new DomainException('Allowed attempts must be at least 1.');
            }
            if ($opensAt && $closesAt && strtotime($closesAt) <= strtotime($opensAt)) {
                throw new DomainException('Closing date must be after opening date.');
            }

            $update = $pdo->prepare(
                'UPDATE lms_quizzes
                 SET title = :title,
                     instructions = :instructions,
                     time_limit_minutes = :time_limit,
                     allowed_attempts = :attempts,
                     passing_score = :passing_score,
                     opens_at = :opens_at,
                     closes_at = :closes_at,
                     is_published = :published
                 WHERE id = :id AND section_subject_id = :ss'
            );
            $update->execute([
                'title'         => $title,
                'instructions'  => $instructions !== '' ? $instructions : null,
                'time_limit'    => $timeLimit,
                'attempts'      => $attempts,
                'passing_score' => $passingScore,
                'opens_at'      => $opensAt,
                'closes_at'     => $closesAt,
                'published'     => $isPublished,
                'id'            => $quizId,
                'ss'            => $sectionSubjectId,
            ]);

            $_SESSION['flash_success'] = 'Quiz settings updated successfully.';
            $redir = filter_input(INPUT_POST, 'redirect_to', FILTER_DEFAULT);
            header('Location: ' . ($redir ? resolveAppUrl($redir) : resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId . '&highlight=' . $quizId)));
            exit;
        }

        /* ===== DELETE QUIZ ================================================= */
        case 'delete_quiz': {
            if ($quizId < 1 || $sectionSubjectId < 1) {
                throw new DomainException('Invalid quiz request.');
            }
            lmsTqVerifyQuiz($pdo, $quizId, $sectionSubjectId);

            $del = $pdo->prepare('DELETE FROM lms_quizzes WHERE id = :id AND section_subject_id = :ss');
            $del->execute(['id' => $quizId, 'ss' => $sectionSubjectId]);

            $_SESSION['flash_success'] = 'Quiz and all associated questions/results deleted successfully.';
            header('Location: ' . resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId));
            exit;
        }

        /* ===== TOGGLE PUBLISH (AJAX) ======================================= */
        case 'toggle_publish': {
            header('Content-Type: application/json; charset=utf-8');
            if ($quizId < 1) {
                echo json_encode(['ok' => false, 'error' => 'Invalid quiz ID.']);
                exit;
            }

            // Verify teacher owns the quiz via section_subjects join
            $check = $pdo->prepare(
                'SELECT q.id, q.is_published, q.section_subject_id
                 FROM lms_quizzes q
                 JOIN section_subjects ss ON ss.id = q.section_subject_id
                 JOIN sections sec ON sec.id = ss.section_id
                 WHERE q.id = :id AND (ss.instructor_id = :u1 OR sec.teacher_id = :u2)
                 LIMIT 1'
            );
            $check->execute(['id' => $quizId, 'u1' => $userId, 'u2' => $userId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['ok' => false, 'error' => 'Quiz not found or permission denied.']);
                exit;
            }

            $newVal = (int)$row['is_published'] === 1 ? 0 : 1;
            $upd = $pdo->prepare('UPDATE lms_quizzes SET is_published = :pub WHERE id = :id');
            $upd->execute(['pub' => $newVal, 'id' => $quizId]);

            echo json_encode(['ok' => true, 'is_published' => $newVal]);
            exit;
        }

        /* ===== ADD QUESTION ================================================ */
        case 'add_question': {
            if ($quizId < 1 || $sectionSubjectId < 1) {
                throw new DomainException('Invalid question request.');
            }
            lmsTqVerifyQuiz($pdo, $quizId, $sectionSubjectId);

            $type   = trim((string)($_POST['question_type'] ?? ''));
            $prompt = trim((string)($_POST['prompt'] ?? ''));
            $points = lmsTqParseScore($_POST['points'] ?? '1', 'Points', false);

            if (!in_array($type, ['multiple_choice', 'true_false', 'identification'], true)) {
                throw new DomainException('Invalid question type selected.');
            }
            if ($prompt === '') {
                throw new DomainException('Question prompt is required.');
            }
            if ($points <= 0) {
                throw new DomainException('Points must be greater than zero.');
            }

            $pdo->beginTransaction();

            // Next display_order
            $doStmt = $pdo->prepare('SELECT COALESCE(MAX(display_order), 0) + 1 FROM lms_quiz_questions WHERE quiz_id = :qid');
            $doStmt->execute(['qid' => $quizId]);
            $displayOrder = (int)$doStmt->fetchColumn();

            $correctAnswer = null;
            if ($type === 'identification') {
                $correctAnswer = trim((string)($_POST['correct_answer'] ?? ''));
                if ($correctAnswer === '') {
                    throw new DomainException('Correct answer is required for identification questions.');
                }
            }

            $qStmt = $pdo->prepare(
                'INSERT INTO lms_quiz_questions (quiz_id, question_type, prompt, points, correct_answer, display_order, created_at)
                 VALUES (:qid, :type, :prompt, :points, :correct_answer, :display_order, NOW())'
            );
            $qStmt->execute([
                'qid'            => $quizId,
                'type'           => $type,
                'prompt'         => $prompt,
                'points'         => $points,
                'correct_answer' => $correctAnswer,
                'display_order'  => $displayOrder,
            ]);
            $newQuestionId = (int)$pdo->lastInsertId();

            if ($type === 'multiple_choice') {
                $choices = $_POST['choices'] ?? [];
                $correctIndex = filter_input(INPUT_POST, 'correct_choice_index', FILTER_VALIDATE_INT);
                if (!is_array($choices) || count($choices) < 2) {
                    throw new DomainException('Multiple choice questions require at least 2 options.');
                }
                if ($correctIndex === false || $correctIndex < 0 || $correctIndex >= count($choices)) {
                    throw new DomainException('Please mark which multiple choice option is correct.');
                }

                $cInsert = $pdo->prepare(
                    'INSERT INTO lms_quiz_choices (question_id, choice_text, is_correct, display_order, created_at)
                     VALUES (:qid, :text, :is_correct, :display_order, NOW())'
                );

                $validChoiceCount = 0;
                foreach ($choices as $idx => $choiceText) {
                    $cleanText = trim((string)$choiceText);
                    if ($cleanText === '') {
                        continue;
                    }
                    $validChoiceCount++;
                    $cInsert->execute([
                        'qid'           => $newQuestionId,
                        'text'          => $cleanText,
                        'is_correct'    => ($idx === $correctIndex) ? 1 : 0,
                        'display_order' => $idx + 1,
                    ]);
                }
                if ($validChoiceCount < 2) {
                    throw new DomainException('Please fill in at least 2 non-empty choice options.');
                }
            } elseif ($type === 'true_false') {
                $correctTf = trim((string)($_POST['correct_tf'] ?? ''));
                if (!in_array($correctTf, ['true', 'false'], true)) {
                    throw new DomainException('Please select whether True or False is the correct answer.');
                }
                $cInsert = $pdo->prepare(
                    'INSERT INTO lms_quiz_choices (question_id, choice_text, is_correct, display_order, created_at)
                     VALUES (:qid, :text, :is_correct, :display_order, NOW())'
                );
                $cInsert->execute([
                    'qid'           => $newQuestionId,
                    'text'          => 'True',
                    'is_correct'    => ($correctTf === 'true') ? 1 : 0,
                    'display_order' => 1,
                ]);
                $cInsert->execute([
                    'qid'           => $newQuestionId,
                    'text'          => 'False',
                    'is_correct'    => ($correctTf === 'false') ? 1 : 0,
                    'display_order' => 2,
                ]);
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Question added successfully.';
            header('Location: ' . resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId . '&view_questions=' . $quizId));
            exit;
        }

        /* ===== EDIT QUESTION =============================================== */
        case 'edit_question': {
            if ($questionId < 1 || $quizId < 1 || $sectionSubjectId < 1) {
                throw new DomainException('Invalid question request.');
            }
            lmsTqVerifyQuiz($pdo, $quizId, $sectionSubjectId);

            // Fetch existing question
            $qCheck = $pdo->prepare('SELECT * FROM lms_quiz_questions WHERE id = :id AND quiz_id = :qid LIMIT 1');
            $qCheck->execute(['id' => $questionId, 'qid' => $quizId]);
            $existingQ = $qCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existingQ) {
                throw new DomainException('Question not found.');
            }

            $type   = $existingQ['question_type']; // type cannot be changed once created to maintain choice schema
            $prompt = trim((string)($_POST['prompt'] ?? ''));
            $points = lmsTqParseScore($_POST['points'] ?? '1', 'Points', false);

            if ($prompt === '') {
                throw new DomainException('Question prompt is required.');
            }
            if ($points <= 0) {
                throw new DomainException('Points must be greater than zero.');
            }

            $pdo->beginTransaction();

            $correctAnswer = null;
            if ($type === 'identification') {
                $correctAnswer = trim((string)($_POST['correct_answer'] ?? ''));
                if ($correctAnswer === '') {
                    throw new DomainException('Correct answer is required for identification questions.');
                }
            }

            $updQ = $pdo->prepare(
                'UPDATE lms_quiz_questions
                 SET prompt = :prompt, points = :points, correct_answer = :correct_answer
                 WHERE id = :id AND quiz_id = :qid'
            );
            $updQ->execute([
                'prompt'         => $prompt,
                'points'         => $points,
                'correct_answer' => $correctAnswer,
                'id'             => $questionId,
                'qid'            => $quizId,
            ]);

            if ($type === 'multiple_choice') {
                $choices = $_POST['choices'] ?? [];
                $correctIndex = filter_input(INPUT_POST, 'correct_choice_index', FILTER_VALIDATE_INT);
                if (!is_array($choices) || count($choices) < 2) {
                    throw new DomainException('Multiple choice questions require at least 2 options.');
                }
                if ($correctIndex === false || $correctIndex < 0 || $correctIndex >= count($choices)) {
                    throw new DomainException('Please mark which multiple choice option is correct.');
                }

                // Delete existing choices and re-insert
                $delC = $pdo->prepare('DELETE FROM lms_quiz_choices WHERE question_id = :qid');
                $delC->execute(['qid' => $questionId]);

                $cInsert = $pdo->prepare(
                    'INSERT INTO lms_quiz_choices (question_id, choice_text, is_correct, display_order, created_at)
                     VALUES (:qid, :text, :is_correct, :display_order, NOW())'
                );

                $validChoiceCount = 0;
                foreach ($choices as $idx => $choiceText) {
                    $cleanText = trim((string)$choiceText);
                    if ($cleanText === '') {
                        continue;
                    }
                    $validChoiceCount++;
                    $cInsert->execute([
                        'qid'           => $questionId,
                        'text'          => $cleanText,
                        'is_correct'    => ($idx === $correctIndex) ? 1 : 0,
                        'display_order' => $idx + 1,
                    ]);
                }
                if ($validChoiceCount < 2) {
                    throw new DomainException('Please fill in at least 2 non-empty choice options.');
                }
            } elseif ($type === 'true_false') {
                $correctTf = trim((string)($_POST['correct_tf'] ?? ''));
                if (!in_array($correctTf, ['true', 'false'], true)) {
                    throw new DomainException('Please select whether True or False is the correct answer.');
                }

                $delC = $pdo->prepare('DELETE FROM lms_quiz_choices WHERE question_id = :qid');
                $delC->execute(['qid' => $questionId]);

                $cInsert = $pdo->prepare(
                    'INSERT INTO lms_quiz_choices (question_id, choice_text, is_correct, display_order, created_at)
                     VALUES (:qid, :text, :is_correct, :display_order, NOW())'
                );
                $cInsert->execute([
                    'qid'           => $questionId,
                    'text'          => 'True',
                    'is_correct'    => ($correctTf === 'true') ? 1 : 0,
                    'display_order' => 1,
                ]);
                $cInsert->execute([
                    'qid'           => $questionId,
                    'text'          => 'False',
                    'is_correct'    => ($correctTf === 'false') ? 1 : 0,
                    'display_order' => 2,
                ]);
            }

            $pdo->commit();
            $_SESSION['flash_success'] = 'Question updated successfully.';
            header('Location: ' . resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId . '&view_questions=' . $quizId));
            exit;
        }

        /* ===== DELETE QUESTION ============================================= */
        case 'delete_question': {
            if ($questionId < 1 || $quizId < 1 || $sectionSubjectId < 1) {
                throw new DomainException('Invalid question request.');
            }
            lmsTqVerifyQuiz($pdo, $quizId, $sectionSubjectId);

            $delQ = $pdo->prepare('DELETE FROM lms_quiz_questions WHERE id = :id AND quiz_id = :qid');
            $delQ->execute(['id' => $questionId, 'qid' => $quizId]);

            $_SESSION['flash_success'] = 'Question deleted successfully.';
            header('Location: ' . resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId . '&view_questions=' . $quizId));
            exit;
        }

        default:
            throw new DomainException('Unknown action request.');
    }
} catch (DomainException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
    $_SESSION['flash_error'] = $e->getMessage();
    $targetRedirect = $returnBase;
    if ($quizId > 0 && ($action === 'add_question' || $action === 'edit_question' || $action === 'delete_question')) {
        $targetRedirect = resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId . '&view_questions=' . $quizId);
    }
    header('Location: ' . $targetRedirect);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('LMS Teacher Quiz Actions error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'A system error occurred. Please try again later.']);
        exit;
    }
    $_SESSION['flash_error'] = 'An unexpected error occurred while processing your request. Please try again.';
    header('Location: ' . $returnBase);
    exit;
}
