<?php
/**
 * Teacher LMS - Quizzes / Exams Management (Phase 6)
 *
 * Modes:
 *   1. Cross-Subject Overview (no ?section_subject_id)
 *   2. Subject-Scoped Quiz List (?section_subject_id=N)
 *   3. Question Builder Sub-mode (?section_subject_id=N&view_questions=QUIZ_ID)
 *   4. Student Results Sub-mode (?section_subject_id=N&view_results=QUIZ_ID)
 *
 * Security:
 *   requireLmsTeacherAccess()   -> role gate
 *   verifyTeacherOwnsSubject()  -> ownership gate
 *
 * Data source:
 *   Reads/writes lms_quizzes, lms_quiz_questions, lms_quiz_choices, lms_quiz_attempts, lms_quiz_responses
 *   Directly connected to the student-side quiz taking flow.
 */

require_once '../includes/lms_access.php';

$teacher          = requireLmsTeacherAccess();
$userId           = $teacher['user_id'];
$sectionSubjectId = (int)filter_input(INPUT_GET, 'section_subject_id', FILTER_VALIDATE_INT);
$viewQuestionsId  = (int)filter_input(INPUT_GET, 'view_questions', FILTER_VALIDATE_INT);
$viewResultsId    = (int)filter_input(INPUT_GET, 'view_results', FILTER_VALIDATE_INT);
$highlightId      = (int)filter_input(INPUT_GET, 'highlight', FILTER_VALIDATE_INT);

// ── Mode detection ─────────────────────────────────────────────────────────────
$subjectMode = false;
$subject     = null;

if ($sectionSubjectId > 0) {
    $subject = verifyTeacherOwnsSubject($pdo, $userId, $sectionSubjectId);
    if (!$subject) {
        $_SESSION['flash_error'] = 'Access denied: you are not assigned to that subject.';
        header('Location: ' . resolveAppUrl('teacher/lms'));
        exit;
    }
    $subjectMode = true;
}

// ── Subject-Scoped: Quiz List ──────────────────────────────────────────────────
$quizzes = [];
if ($subjectMode && !$viewQuestionsId && !$viewResultsId) {
    $qStmt = $pdo->prepare(
        "SELECT q.*,
                COUNT(DISTINCT quest.id) AS question_count,
                COALESCE(SUM(quest.points), 0) AS total_points,
                COUNT(DISTINCT att.id) AS total_attempts,
                COUNT(DISTINCT CASE WHEN att.status != 'in_progress' THEN att.id END) AS completed_attempts,
                AVG(CASE WHEN att.status != 'in_progress' THEN att.score END) AS avg_score
         FROM lms_quizzes q
         LEFT JOIN lms_quiz_questions quest ON quest.quiz_id = q.id
         LEFT JOIN lms_quiz_attempts att ON att.quiz_id = q.id
         WHERE q.section_subject_id = :ss
         GROUP BY q.id
         ORDER BY q.display_order ASC, q.id ASC"
    );
    $qStmt->execute(['ss' => $sectionSubjectId]);
    $quizzes = $qStmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Question Builder Sub-mode ──────────────────────────────────────────────────
$builderQuiz      = null;
$builderQuestions = [];
$choicesByQuestion= [];

if ($subjectMode && $viewQuestionsId > 0) {
    $bqStmt = $pdo->prepare('SELECT * FROM lms_quizzes WHERE id = :id AND section_subject_id = :ss LIMIT 1');
    $bqStmt->execute(['id' => $viewQuestionsId, 'ss' => $sectionSubjectId]);
    $builderQuiz = $bqStmt->fetch(PDO::FETCH_ASSOC);

    if (!$builderQuiz) {
        $_SESSION['flash_error'] = 'Quiz not found or does not belong to this subject.';
        header('Location: ' . resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId));
        exit;
    }

    $questStmt = $pdo->prepare(
        'SELECT * FROM lms_quiz_questions WHERE quiz_id = :qid ORDER BY display_order ASC, id ASC'
    );
    $questStmt->execute(['qid' => $viewQuestionsId]);
    $builderQuestions = $questStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($builderQuestions)) {
        $qIds = array_column($builderQuestions, 'id');
        $inPh = implode(',', array_fill(0, count($qIds), '?'));
        $cStmt = $pdo->prepare(
            "SELECT * FROM lms_quiz_choices WHERE question_id IN ($inPh) ORDER BY display_order ASC, id ASC"
        );
        $cStmt->execute($qIds);
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $choicesByQuestion[(int)$c['question_id']][] = $c;
        }
    }
}

// ── Student Results Sub-mode ───────────────────────────────────────────────────
$resultsQuiz      = null;
$studentAttempts  = [];
$resultsSummary   = [
    'enrolled'  => 0,
    'attempted' => 0,
    'completed' => 0,
    'passed'    => 0,
    'avg_score' => 0.0,
    'max_score' => 0.0,
];
$attemptResponses = [];

if ($subjectMode && $viewResultsId > 0) {
    $rqStmt = $pdo->prepare(
        "SELECT q.*,
                (SELECT COUNT(*) FROM lms_quiz_questions quest WHERE quest.quiz_id = q.id) AS question_count,
                (SELECT COALESCE(SUM(points), 0) FROM lms_quiz_questions quest WHERE quest.quiz_id = q.id) AS total_points
         FROM lms_quizzes q
         WHERE q.id = :id AND q.section_subject_id = :ss
         LIMIT 1"
    );
    $rqStmt->execute(['id' => $viewResultsId, 'ss' => $sectionSubjectId]);
    $resultsQuiz = $rqStmt->fetch(PDO::FETCH_ASSOC);

    if (!$resultsQuiz) {
        $_SESSION['flash_error'] = 'Quiz not found or does not belong to this subject.';
        header('Location: ' . resolveAppUrl('teacher/lms_quizzes?section_subject_id=' . $sectionSubjectId));
        exit;
    }

    // Fetch enrolled students and any attempts they have taken
    $attStmt = $pdo->prepare(
        "SELECT s.id AS student_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username) AS student_name,
                u.email AS student_email,
                a.id AS attempt_id, a.attempt_number, a.status, a.started_at, a.deadline_at, a.submitted_at,
                a.score, a.total_points, a.feedback
         FROM enrollments e
         JOIN students s ON s.id = e.student_id
         JOIN users u ON u.id = s.user_id
         JOIN section_subjects ss ON ss.section_id = e.section_id
         LEFT JOIN lms_quiz_attempts a ON a.quiz_id = :qid AND a.student_id = s.id
         WHERE ss.id = :ss
           AND e.status = 'enrolled'
         ORDER BY student_name ASC, a.attempt_number ASC"
    );
    $attStmt->execute(['qid' => $viewResultsId, 'ss' => $sectionSubjectId]);
    $rawAttempts = $attStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by student
    $studentsGrouped = [];
    $attemptedStudentIds = [];
    $totalCompletedScores = [];
    $passCount = 0;
    $passingScore = $resultsQuiz['passing_score'] !== null ? (float)$resultsQuiz['passing_score'] : null;

    foreach ($rawAttempts as $row) {
        $stId = (int)$row['student_id'];
        if (!isset($studentsGrouped[$stId])) {
            $studentsGrouped[$stId] = [
                'student_id'   => $stId,
                'student_name' => $row['student_name'],
                'student_email'=> $row['student_email'],
                'attempts'     => [],
            ];
        }
        if ($row['attempt_id'] !== null) {
            $studentsGrouped[$stId]['attempts'][] = $row;
            $attemptedStudentIds[$stId] = true;
            if ($row['status'] !== 'in_progress' && $row['score'] !== null) {
                $totalCompletedScores[] = (float)$row['score'];
                if ($passingScore !== null && (float)$row['score'] >= $passingScore) {
                    $passCount++;
                }
            }
        }
    }
    $studentAttempts = array_values($studentsGrouped);

    $resultsSummary['enrolled']  = count($studentsGrouped);
    $resultsSummary['attempted'] = count($attemptedStudentIds);
    $resultsSummary['completed'] = count($totalCompletedScores);
    $resultsSummary['passed']    = $passCount;
    $resultsSummary['avg_score'] = count($totalCompletedScores) > 0 ? round(array_sum($totalCompletedScores) / count($totalCompletedScores), 2) : 0.0;
    $resultsSummary['max_score'] = (float)$resultsQuiz['total_points'];

    // Load responses for all completed attempts to support quick answer breakdown
    $attemptIds = [];
    foreach ($rawAttempts as $r) {
        if (!empty($r['attempt_id'])) {
            $attemptIds[] = (int)$r['attempt_id'];
        }
    }
    if (!empty($attemptIds)) {
        $inAtt = implode(',', array_fill(0, count($attemptIds), '?'));
        $respStmt = $pdo->prepare(
            "SELECT r.attempt_id, r.question_id, r.choice_id, r.answer_text, r.answered_at,
                    q.prompt, q.question_type, q.points, q.correct_answer,
                    c.choice_text AS student_choice_text,
                    (SELECT c2.choice_text FROM lms_quiz_choices c2 WHERE c2.question_id = q.id AND c2.is_correct = 1 LIMIT 1) AS correct_choice_text
             FROM lms_quiz_responses r
             JOIN lms_quiz_questions q ON q.id = r.question_id
             LEFT JOIN lms_quiz_choices c ON c.id = r.choice_id
             WHERE r.attempt_id IN ($inAtt)
             ORDER BY q.display_order ASC, q.id ASC"
        );
        $respStmt->execute($attemptIds);
        foreach ($respStmt->fetchAll(PDO::FETCH_ASSOC) as $resp) {
            $attemptResponses[(int)$resp['attempt_id']][] = $resp;
        }
    }
}

// ── Overview Mode: load all assigned subjects ──────────────────────────────────
$allSubjects = [];
if (!$subjectMode) {
    $allSubjects = fetchLmsTeacherSubjects($pdo, $userId);
    if (!empty($allSubjects)) {
        $ssIds = array_column($allSubjects, 'section_subject_id');
        $inPh  = implode(',', array_fill(0, count($ssIds), '?'));
        $cntStmt = $pdo->prepare(
            "SELECT q.section_subject_id,
                    COUNT(DISTINCT q.id) AS total_quizzes,
                    SUM(CASE WHEN q.is_published = 1 THEN 1 ELSE 0 END) AS published_quizzes,
                    COUNT(DISTINCT a.id) AS total_attempts
             FROM lms_quizzes q
             LEFT JOIN lms_quiz_attempts a ON a.quiz_id = q.id
             WHERE q.section_subject_id IN ($inPh)
             GROUP BY q.section_subject_id"
        );
        $cntStmt->execute($ssIds);
        $countsBySs = [];
        foreach ($cntStmt->fetchAll(PDO::FETCH_ASSOC) as $cnt) {
            $countsBySs[(int)$cnt['section_subject_id']] = [
                'total'     => (int)$cnt['total_quizzes'],
                'published' => (int)$cnt['published_quizzes'],
                'attempts'  => (int)$cnt['total_attempts'],
            ];
        }
        foreach ($allSubjects as &$s) {
            $sId = (int)$s['section_subject_id'];
            $s['quiz_count']      = $countsBySs[$sId]['total'] ?? 0;
            $s['published_count'] = $countsBySs[$sId]['published'] ?? 0;
            $s['attempt_count']   = $countsBySs[$sId]['attempts'] ?? 0;
        }
        unset($s);
    }
}

$page_title = $subjectMode
    ? ($builderQuiz ? 'Questions: ' . $builderQuiz['title'] : ($resultsQuiz ? 'Results: ' . $resultsQuiz['title'] : $subject['subject_code'] . ' — Quizzes & Exams'))
    : 'LMS — Quizzes & Exams';

require_once '../includes/header.php';

$lmsActiveTab = 'quizzes';
require_once __DIR__ . '/lms_navbar.php';
?>

<?php if ($subjectMode): ?>

    <!-- ════════════════════════════════════════════════════════════════════
         SUBJECT MODE
         ════════════════════════════════════════════════════════════════════ -->

    <?php if ($builderQuiz): ?>
        <!-- ── SUB-MODE: QUESTION BUILDER ──────────────────────────────── -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb mb-0" style="font-size:.82rem;">
                <li class="breadcrumb-item"><a href="lms_quizzes" class="text-decoration-none text-brand-primary">Quizzes Overview</a></li>
                <li class="breadcrumb-item"><a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>" class="text-decoration-none text-brand-primary"><?php echo htmlspecialchars($subject['subject_code']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Question Builder</li>
            </ol>
        </nav>

        <div class="card shadow-sm border-0 mb-4" style="border-left: 4px solid var(--brand-primary,#0b4f5c) !important;">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge" style="background:var(--brand-primary);"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                            <?php if ($builderQuiz['is_published']): ?>
                                <span class="badge text-bg-success"><i class="bi bi-eye me-1"></i>Published</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary"><i class="bi bi-pencil me-1"></i>Draft</span>
                            <?php endif; ?>
                        </div>
                        <h1 class="h3 fw-bold text-navy-alt mb-1"><?php echo htmlspecialchars($builderQuiz['title']); ?></h1>
                        <p class="text-muted small mb-0">
                            Time limit: <strong><?php echo (int)$builderQuiz['time_limit_minutes']; ?> min</strong> ·
                            Allowed attempts: <strong><?php echo (int)$builderQuiz['allowed_attempts']; ?></strong> ·
                            Passing score: <strong><?php echo $builderQuiz['passing_score'] !== null ? htmlspecialchars((string)$builderQuiz['passing_score']) . ' pts' : 'None set'; ?></strong>
                        </p>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i>Back to Quizzes
                        </a>
                        <a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>&view_results=<?php echo $builderQuiz['id']; ?>" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-bar-chart me-1"></i>View Results
                        </a>
                        <button class="btn btn-brand-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-add-question">
                            <i class="bi bi-plus-circle me-1"></i>Add Question
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Question list -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 fw-bold text-navy-alt mb-0">
                <i class="bi bi-list-check me-2 text-brand-primary"></i>Questions (<?php echo count($builderQuestions); ?>)
            </h2>
            <?php
                $computedTotalPoints = 0.0;
                foreach ($builderQuestions as $bq) {
                    $computedTotalPoints += (float)$bq['points'];
                }
            ?>
            <span class="badge bg-light text-dark border px-3 py-2 fw-semibold">
                Total Points: <span class="text-brand-primary fw-bold"><?php echo round($computedTotalPoints, 2); ?></span>
            </span>
        </div>

        <?php if (empty($builderQuestions)): ?>
            <div class="card shadow-sm border-0 text-center py-5">
                <div class="card-body">
                    <i class="bi bi-question-circle" style="font-size:3rem;opacity:.3;color:var(--brand-primary);"></i>
                    <h3 class="h6 fw-bold mt-3 mb-1">No questions added yet</h3>
                    <p class="text-muted small mb-3">Add Multiple Choice, True/False, or Identification questions so students can take this quiz.</p>
                    <button class="btn btn-brand-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-add-question">
                        <i class="bi bi-plus-circle me-1"></i>Add First Question
                    </button>
                </div>
            </div>
        <?php else: ?>
            <div class="vstack gap-3 mb-5">
                <?php foreach ($builderQuestions as $idx => $quest):
                    $qId = (int)$quest['id'];
                    $qType = $quest['question_type'];
                    $qChoices = $choicesByQuestion[$qId] ?? [];

                    $typeBadges = [
                        'multiple_choice' => ['Multiple Choice', 'text-bg-primary'],
                        'true_false'      => ['True / False', 'text-bg-info'],
                        'identification'  => ['Identification', 'text-bg-warning'],
                    ];
                    [$typeLabel, $typeBadgeClass] = $typeBadges[$qType] ?? ['Unknown', 'text-bg-secondary'];
                ?>
                <div class="card shadow-sm border-0" id="question-card-<?php echo $qId; ?>">
                    <div class="card-body p-3 p-md-4">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="badge bg-secondary" style="font-size:.8rem;">#<?php echo $idx + 1; ?></span>
                                <span class="badge <?php echo $typeBadgeClass; ?>"><?php echo htmlspecialchars($typeLabel); ?></span>
                                <span class="badge bg-light text-muted border">
                                    <?php echo htmlspecialchars((string)$quest['points']); ?> pt<?php echo (float)$quest['points'] === 1.0 ? '' : 's'; ?>
                                </span>
                            </div>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        onclick="openEditQuestionModal(<?php echo htmlspecialchars(json_encode([
                                            'id'             => $qId,
                                            'type'           => $qType,
                                            'prompt'         => $quest['prompt'],
                                            'points'         => $quest['points'],
                                            'correct_answer' => $quest['correct_answer'],
                                            'choices'        => $qChoices,
                                        ]), ENT_QUOTES, 'UTF-8'); ?>)"
                                        title="Edit question">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        onclick="openDeleteQuestionModal(<?php echo $qId; ?>, <?php echo $builderQuiz['id']; ?>, <?php echo $sectionSubjectId; ?>)"
                                        title="Delete question">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <div class="fw-semibold text-dark mb-3" style="font-size:1rem;">
                            <?php echo nl2br(htmlspecialchars($quest['prompt'])); ?>
                        </div>

                        <?php if ($qType === 'multiple_choice'): ?>
                            <div class="row g-2">
                                <?php foreach ($qChoices as $cIdx => $choice): ?>
                                    <div class="col-12 col-md-6">
                                        <div class="p-2 px-3 rounded border d-flex align-items-center justify-content-between <?php echo $choice['is_correct'] ? 'bg-success-subtle border-success text-success-emphasis fw-semibold' : 'bg-light text-muted'; ?>" style="font-size:.88rem;">
                                            <span>
                                                <strong class="me-2"><?php echo chr(65 + $cIdx); ?>.</strong>
                                                <?php echo htmlspecialchars($choice['choice_text']); ?>
                                            </span>
                                            <?php if ($choice['is_correct']): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Correct</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($qType === 'true_false'): ?>
                            <div class="d-flex gap-3">
                                <?php foreach ($qChoices as $choice): ?>
                                    <div class="p-2 px-3 rounded border d-flex align-items-center gap-2 <?php echo $choice['is_correct'] ? 'bg-success-subtle border-success text-success-emphasis fw-semibold' : 'bg-light text-muted'; ?>" style="font-size:.88rem; min-width: 140px;">
                                        <i class="bi <?php echo $choice['is_correct'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted'; ?>"></i>
                                        <span><?php echo htmlspecialchars($choice['choice_text']); ?></span>
                                        <?php if ($choice['is_correct']): ?>
                                            <span class="badge bg-success ms-auto">Correct</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($qType === 'identification'): ?>
                            <div class="p-2 px-3 rounded border bg-success-subtle border-success text-success-emphasis d-flex align-items-center gap-2" style="font-size:.88rem;">
                                <i class="bi bi-check2-all text-success fs-5"></i>
                                <div>
                                    <span class="small text-muted d-block">Accepted correct answer:</span>
                                    <strong class="font-monospace fs-6"><?php echo htmlspecialchars((string)$quest['correct_answer']); ?></strong>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($resultsQuiz): ?>
        <!-- ── SUB-MODE: STUDENT RESULTS ───────────────────────────────── -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb mb-0" style="font-size:.82rem;">
                <li class="breadcrumb-item"><a href="lms_quizzes" class="text-decoration-none text-brand-primary">Quizzes Overview</a></li>
                <li class="breadcrumb-item"><a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>" class="text-decoration-none text-brand-primary"><?php echo htmlspecialchars($subject['subject_code']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page">Student Results</li>
            </ol>
        </nav>

        <div class="card shadow-sm border-0 mb-4" style="border-left: 4px solid var(--brand-primary,#0b4f5c) !important;">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge" style="background:var(--brand-primary);"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                            <span class="badge text-bg-info"><i class="bi bi-bar-chart me-1"></i>Quiz Analytics</span>
                        </div>
                        <h1 class="h3 fw-bold text-navy-alt mb-1"><?php echo htmlspecialchars($resultsQuiz['title']); ?></h1>
                        <p class="text-muted small mb-0">
                            Total Points: <strong><?php echo (float)$resultsQuiz['total_points']; ?> pts</strong> ·
                            Passing score: <strong><?php echo $resultsQuiz['passing_score'] !== null ? htmlspecialchars((string)$resultsQuiz['passing_score']) . ' pts' : 'None set'; ?></strong> ·
                            Time limit: <strong><?php echo (int)$resultsQuiz['time_limit_minutes']; ?> min</strong> ·
                            Allowed attempts: <strong><?php echo (int)$resultsQuiz['allowed_attempts']; ?></strong>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i>Back to Quizzes
                        </a>
                        <a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>&view_questions=<?php echo $resultsQuiz['id']; ?>" class="btn btn-brand-primary btn-sm">
                            <i class="bi bi-list-check me-1"></i>Manage Questions
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI Strip -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                    <div class="d-flex align-items-center" style="gap: 14px;">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(0, 128, 128, 0.1); color: var(--brand-primary, #008080);">
                            <i class="bi bi-people fs-5"></i>
                        </div>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Class Enrollment</span>
                            <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $resultsSummary['enrolled']; ?></span>
                            <span class="text-muted" style="font-size: 0.75rem;">students enrolled</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                    <div class="d-flex align-items-center" style="gap: 14px;">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                            <i class="bi bi-pencil-square fs-5"></i>
                        </div>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Attempts Made</span>
                            <span class="fs-5 fw-bold text-navy-alt lh-1"><?php echo $resultsSummary['attempted']; ?> <span class="text-muted fw-normal fs-6">/ <?php echo $resultsSummary['enrolled']; ?></span></span>
                            <span class="text-muted" style="font-size: 0.75rem;"><?php echo $resultsSummary['enrolled'] > 0 ? round(($resultsSummary['attempted'] / $resultsSummary['enrolled']) * 100) : 0; ?>% participation</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                    <div class="d-flex align-items-center" style="gap: 14px;">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(25, 135, 84, 0.1); color: #198754;">
                            <i class="bi bi-graph-up fs-5"></i>
                        </div>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Average Score</span>
                            <span class="fs-5 fw-bold text-success lh-1"><?php echo $resultsSummary['avg_score']; ?> <span class="text-muted fw-normal fs-6">/ <?php echo $resultsSummary['max_score']; ?></span></span>
                            <span class="text-muted" style="font-size: 0.75rem;"><?php echo $resultsSummary['max_score'] > 0 ? round(($resultsSummary['avg_score'] / $resultsSummary['max_score']) * 100, 1) : 0; ?>% average</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card card-premium shadow-sm border-0 h-100" style="padding: 18px 20px;">
                    <div class="d-flex align-items-center" style="gap: 14px;">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: rgba(255, 193, 7, 0.15); color: #b78103;">
                            <i class="bi bi-award fs-5"></i>
                        </div>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <span class="text-muted text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px;">Pass Rate</span>
                            <?php if ($resultsQuiz['passing_score'] !== null): ?>
                                <?php $passPct = $resultsSummary['completed'] > 0 ? round(($resultsSummary['passed'] / $resultsSummary['completed']) * 100, 1) : 0; ?>
                                <span class="fs-5 fw-bold <?php echo $passPct >= 75 ? 'text-success' : 'text-warning'; ?> lh-1"><?php echo $passPct; ?>%</span>
                                <span class="text-muted" style="font-size: 0.75rem;"><?php echo $resultsSummary['passed']; ?> passed of <?php echo $resultsSummary['completed']; ?></span>
                            <?php else: ?>
                                <span class="fs-5 fw-bold text-muted lh-1">—</span>
                                <span class="text-muted" style="font-size: 0.75rem;">No pass mark set</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student results table -->
        <div class="card card-premium shadow-sm border-0 mb-5">
            <div class="card-header card-header-premium py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h6 fw-bold text-navy-alt mb-0"><i class="bi bi-people me-2 text-brand-primary"></i>Student Attempt Records</h2>
                <span class="badge bg-light text-muted border"><?php echo count($studentAttempts); ?> enrolled</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.88rem;">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4" style="padding-left: 24px !important;">Student Name</th>
                            <th>Attempt #</th>
                            <th>Status</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Pass / Fail</th>
                            <th>Submitted Date</th>
                            <th class="text-end pe-4" style="padding-right: 24px !important;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($studentAttempts)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">No students currently enrolled in this subject.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($studentAttempts as $st):
                                $atts = $st['attempts'];
                                $hasAttempt = !empty($atts);
                            ?>
                                <?php if (!$hasAttempt): ?>
                                    <tr>
                                        <td class="ps-4" style="padding-left: 24px !important;">
                                            <div class="fw-semibold text-dark"><?php echo htmlspecialchars($st['student_name']); ?></div>
                                            <div class="text-muted" style="font-size:.75rem;"><?php echo htmlspecialchars($st['student_email']); ?></div>
                                        </td>
                                        <td>—</td>
                                        <td><span class="badge bg-light text-secondary border">Not Attempted</span></td>
                                        <td>—</td>
                                        <td>—</td>
                                        <td>—</td>
                                        <td>—</td>
                                        <td class="text-end pe-4 text-muted" style="font-size:.8rem; padding-right: 24px !important;">No activity</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($atts as $attIdx => $att):
                                        $scoreVal = $att['score'] !== null ? (float)$att['score'] : null;
                                        $totPts   = (float)$att['total_points'] > 0 ? (float)$att['total_points'] : (float)$resultsQuiz['total_points'];
                                        $pct      = ($scoreVal !== null && $totPts > 0) ? round(($scoreVal / $totPts) * 100, 1) : null;
                                        $isPassed = ($scoreVal !== null && $resultsQuiz['passing_score'] !== null)
                                            ? ($scoreVal >= (float)$resultsQuiz['passing_score'])
                                            : null;

                                        $statusBadges = [
                                            'submitted'   => ['Submitted', 'text-bg-success'],
                                            'in_progress' => ['In Progress', 'text-bg-primary'],
                                            'timed_out'   => ['Timed Out', 'text-bg-warning'],
                                            'interrupted' => ['Interrupted', 'text-bg-danger'],
                                        ];
                                        [$statLabel, $statBadgeClass] = $statusBadges[$att['status']] ?? [$att['status'], 'text-bg-secondary'];
                                        $attId = (int)$att['attempt_id'];
                                        $hasResponses = !empty($attemptResponses[$attId]);
                                    ?>
                                        <tr>
                                            <td class="ps-4" style="padding-left: 24px !important;">
                                                <?php if ($attIdx === 0): ?>
                                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($st['student_name']); ?></div>
                                                    <div class="text-muted" style="font-size:.75rem;"><?php echo htmlspecialchars($st['student_email']); ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted ms-3" style="font-size:.75rem;">↳ (same student)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    Attempt <?php echo (int)$att['attempt_number']; ?>
                                                </span>
                                            </td>
                                            <td><span class="badge <?php echo $statBadgeClass; ?>"><?php echo htmlspecialchars($statLabel); ?></span></td>
                                            <td>
                                                <?php if ($scoreVal !== null): ?>
                                                    <strong><?php echo $scoreVal; ?></strong> <span class="text-muted">/ <?php echo $totPts; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($pct !== null): ?>
                                                    <span class="fw-semibold <?php echo $pct >= 75 ? 'text-success' : 'text-danger'; ?>"><?php echo $pct; ?>%</span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isPassed !== null): ?>
                                                    <span class="badge <?php echo $isPassed ? 'text-bg-success' : 'text-bg-danger'; ?>">
                                                        <i class="bi <?php echo $isPassed ? 'bi-check-circle' : 'bi-x-circle'; ?> me-1"></i>
                                                        <?php echo $isPassed ? 'Passed' : 'Failed'; ?>
                                                    </span>
                                                <?php elseif ($resultsQuiz['passing_score'] === null && $scoreVal !== null): ?>
                                                    <span class="text-muted small">No pass mark</span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="small text-muted">
                                                <?php echo !empty($att['submitted_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime($att['submitted_at']))) : (!empty($att['started_at']) ? 'Started ' . htmlspecialchars(date('M j, Y g:i A', strtotime($att['started_at']))) : '—'); ?>
                                            </td>
                                            <td class="text-end pe-4" style="padding-right: 24px !important;">
                                                <?php if ($hasResponses): ?>
                                                    <button type="button" class="btn btn-outline-primary btn-sm"
                                                            onclick="openResponsesModal(<?php echo $attId; ?>, '<?php echo htmlspecialchars(addslashes($st['student_name'])); ?>', <?php echo (int)$att['attempt_number']; ?>)">
                                                        <i class="bi bi-eye me-1"></i>Review
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted small">No responses</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>
        <!-- ── DEFAULT: SUBJECT QUIZ LIST ──────────────────────────────── -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb mb-0" style="font-size:.82rem;">
                <li class="breadcrumb-item"><a href="lms_quizzes" class="text-decoration-none text-brand-primary">Quizzes Overview</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($subject['subject_code']); ?></li>
            </ol>
        </nav>

        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="text-uppercase small fw-bold text-brand-primary mb-1" style="letter-spacing:.06em;"><?php echo htmlspecialchars($subject['subject_code']); ?> &middot; <?php echo htmlspecialchars($subject['section_name'] ?: 'Section'); ?></div>
                <h3 class="m-0 text-navy-alt"><?php echo htmlspecialchars($subject['subject_name']); ?></h3>
                <p class="text-muted small m-0">
                    Quizzes, examinations, and assessment results for <?php echo htmlspecialchars($subject['section_name'] ?: 'this section'); ?>
                    <?php if (!empty($subject['year_level'])): ?> &middot; <?php echo htmlspecialchars($subject['year_level']); ?><?php endif; ?>
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="lms_subject?section_subject_id=<?php echo $sectionSubjectId; ?>&section=overview" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 shadow-sm">
                    <i class="bi bi-arrow-left"></i> Subject Hub
                </a>
                <button class="btn btn-brand-primary btn-sm d-inline-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#modal-create-quiz">
                    <i class="bi bi-plus-lg"></i> Create Quiz
                </button>
            </div>
        </div>

        <!-- Stat Summary Strip matching Gradebook -->
        <?php
            $totalQuizzesCount     = count($quizzes);
            $totalPublishedCount   = 0;
            $totalQuestionsCount   = 0;
            $totalAttemptsCount    = 0;
            foreach ($quizzes as $qz) {
                if ($qz['is_published']) $totalPublishedCount++;
                $totalQuestionsCount += (int)$qz['question_count'];
                $totalAttemptsCount  += (int)$qz['total_attempts'];
            }
        ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card card-premium shadow-sm border-0 h-100" style="border-radius: 10px; background: #ffffff;">
                    <div class="card-body d-flex align-items-center" style="padding: 18px 20px; gap: 14px;">
                        <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem;">
                            <i class="bi bi-patch-question-fill"></i>
                        </span>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px; line-height: 1.2;">Total Quizzes</div>
                            <div class="fw-bold text-navy-alt fs-5" style="line-height: 1.1;"><?php echo $totalQuizzesCount; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card card-premium shadow-sm border-0 h-100" style="border-radius: 10px; background: #ffffff;">
                    <div class="card-body d-flex align-items-center" style="padding: 18px 20px; gap: 14px;">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem; background: #dcfce7; color: #166534;">
                            <i class="bi bi-check2-circle"></i>
                        </span>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px; line-height: 1.2;">Published</div>
                            <div class="fw-bold text-success fs-5" style="line-height: 1.1;"><?php echo $totalPublishedCount; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card card-premium shadow-sm border-0 h-100" style="border-radius: 10px; background: #ffffff;">
                    <div class="card-body d-flex align-items-center" style="padding: 18px 20px; gap: 14px;">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem; background: #e0f2fe; color: #0369a1;">
                            <i class="bi bi-list-check"></i>
                        </span>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px; line-height: 1.2;">Questions Created</div>
                            <div class="fw-bold text-navy-alt fs-5" style="line-height: 1.1;"><?php echo $totalQuestionsCount; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card card-premium shadow-sm border-0 h-100" style="border-radius: 10px; background: #ffffff;">
                    <div class="card-body d-flex align-items-center" style="padding: 18px 20px; gap: 14px;">
                        <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0" style="width: 44px; height: 44px; font-size: 1.25rem; background: #fef3c7; color: #92400e;">
                            <i class="bi bi-people-fill"></i>
                        </span>
                        <div class="d-flex flex-column" style="gap: 4px;">
                            <div class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.5px; line-height: 1.2;">Student Attempts</div>
                            <div class="fw-bold text-warning-emphasis fs-5" style="line-height: 1.1;"><?php echo $totalAttemptsCount; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quizzes Container Card -->
        <div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <h5 class="card-title m-0 fw-semibold text-navy-alt">
                    <i class="bi bi-patch-question me-1.5 text-brand-primary"></i>
                    Quizzes &amp; Examinations
                </h5>
                <span class="badge bg-secondary-subtle text-brand-primary border">
                    <?php echo count($quizzes); ?> Quiz<?php echo count($quizzes) !== 1 ? 'zes' : ''; ?>
                </span>
            </div>
            <div class="card-body card-body-premium p-4">
                <?php if (empty($quizzes)): ?>
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 56px; height: 56px; font-size: 1.5rem;">
                                <i class="bi bi-patch-question"></i>
                            </span>
                        </div>
                        <h5 class="text-navy-alt fw-bold mb-1">No quizzes created yet</h5>
                        <p class="text-muted small mb-3">Create your first quiz or exam for <?php echo htmlspecialchars($subject['subject_code']); ?>.</p>
                        <button class="btn btn-brand-primary btn-sm shadow-sm" data-bs-toggle="modal" data-bs-target="#modal-create-quiz">
                            <i class="bi bi-plus-lg me-1"></i>Create First Quiz
                        </button>
                    </div>
                <?php else: ?>
                    <div class="vstack gap-3 w-100">
                <?php foreach ($quizzes as $quiz):
                    $qid = (int)$quiz['id'];
                    $now = time();
                    $openTs = $quiz['opens_at'] ? strtotime($quiz['opens_at']) : null;
                    $closeTs = $quiz['closes_at'] ? strtotime($quiz['closes_at']) : null;
                    $availBadge = ['Available', 'text-bg-success'];
                    if ($openTs && $now < $openTs) {
                        $availBadge = ['Upcoming', 'text-bg-secondary'];
                    } elseif ($closeTs && $now > $closeTs) {
                        $availBadge = ['Closed', 'text-bg-danger'];
                    }
                ?>
                <div class="card shadow-sm border-0 <?php echo $highlightId === $qid ? 'border-primary ring-2' : ''; ?>" id="quiz-card-<?php echo $qid; ?>">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                    <h2 class="h5 fw-bold text-navy-alt mb-0"><?php echo htmlspecialchars($quiz['title']); ?></h2>
                                    <span class="badge <?php echo $availBadge[1]; ?>"><?php echo $availBadge[0]; ?></span>
                                    <span class="badge <?php echo $quiz['is_published'] ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                        <?php echo $quiz['is_published'] ? 'Published' : 'Draft'; ?>
                                    </span>
                                </div>
                                <?php if (!empty($quiz['instructions'])): ?>
                                    <p class="text-muted small mb-2"><?php echo nl2br(htmlspecialchars($quiz['instructions'])); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <!-- Publish Toggle Switch -->
                                <div class="form-check form-switch me-2" title="Publish / Unpublish toggle">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="pub-toggle-<?php echo $qid; ?>"
                                           <?php echo $quiz['is_published'] ? 'checked' : ''; ?>
                                           onchange="toggleQuizPublish(<?php echo $qid; ?>, this)">
                                    <label class="form-check-label small text-muted" for="pub-toggle-<?php echo $qid; ?>">
                                        <span id="pub-label-<?php echo $qid; ?>"><?php echo $quiz['is_published'] ? 'Live' : 'Hidden'; ?></span>
                                    </label>
                                </div>

                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        onclick="openEditQuizModal(<?php echo htmlspecialchars(json_encode([
                                            'id'                 => $qid,
                                            'title'              => $quiz['title'],
                                            'instructions'       => $quiz['instructions'] ?? '',
                                            'time_limit_minutes' => (int)$quiz['time_limit_minutes'],
                                            'allowed_attempts'   => (int)$quiz['allowed_attempts'],
                                            'passing_score'      => $quiz['passing_score'],
                                            'opens_at'           => $quiz['opens_at'] ? date('Y-m-d\TH:i', strtotime($quiz['opens_at'])) : '',
                                            'closes_at'          => $quiz['closes_at'] ? date('Y-m-d\TH:i', strtotime($quiz['closes_at'])) : '',
                                            'is_published'       => (int)$quiz['is_published'],
                                        ]), ENT_QUOTES, 'UTF-8'); ?>)"
                                        title="Edit quiz settings">
                                    <i class="bi bi-gear me-1"></i>Edit
                                </button>

                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        onclick="openDeleteQuizModal(<?php echo $qid; ?>, '<?php echo htmlspecialchars(addslashes($quiz['title'])); ?>')"
                                        title="Delete quiz">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Quiz Meta Badges -->
                        <div class="d-flex flex-wrap gap-2 align-items-center my-3 text-muted small">
                            <span class="badge bg-light text-dark border px-2 py-1">
                                <i class="bi bi-clock me-1 text-primary"></i><?php echo (int)$quiz['time_limit_minutes']; ?> min
                            </span>
                            <span class="badge bg-light text-dark border px-2 py-1">
                                <i class="bi bi-arrow-repeat me-1 text-info"></i><?php echo (int)$quiz['allowed_attempts']; ?> attempt<?php echo (int)$quiz['allowed_attempts'] === 1 ? '' : 's'; ?>
                            </span>
                            <span class="badge bg-light text-dark border px-2 py-1">
                                <i class="bi bi-award me-1 text-warning"></i>
                                Passing: <?php echo $quiz['passing_score'] !== null ? htmlspecialchars((string)$quiz['passing_score']) . ' pts' : 'None'; ?>
                            </span>
                            <span class="badge bg-light text-dark border px-2 py-1">
                                <i class="bi bi-calculator me-1 text-secondary"></i>
                                <?php echo (int)$quiz['question_count']; ?> question<?php echo (int)$quiz['question_count'] === 1 ? '' : 's'; ?> (<?php echo (float)$quiz['total_points']; ?> pts)
                            </span>
                            <?php if (!empty($quiz['opens_at'])): ?>
                                <span class="badge bg-light text-muted border px-2 py-1">
                                    Opens: <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($quiz['opens_at']))); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($quiz['closes_at'])): ?>
                                <span class="badge bg-light text-muted border px-2 py-1">
                                    Closes: <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($quiz['closes_at']))); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Action Bar -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 border-top gap-2">
                            <div class="small text-muted">
                                <i class="bi bi-people me-1"></i>
                                <strong><?php echo (int)$quiz['total_attempts']; ?></strong> attempts taken
                                <?php if ((int)$quiz['completed_attempts'] > 0 && $quiz['avg_score'] !== null): ?>
                                    · Class avg: <strong class="text-success"><?php echo round((float)$quiz['avg_score'], 1); ?> pts</strong>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-2" style="gap: 8px;">
                                <a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>&view_questions=<?php echo $qid; ?>"
                                   class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 shadow-sm">
                                    <i class="bi bi-list-check"></i> Questions (<?php echo (int)$quiz['question_count']; ?>)
                                </a>
                                <a href="lms_quizzes?section_subject_id=<?php echo $sectionSubjectId; ?>&view_results=<?php echo $qid; ?>"
                                   class="btn btn-brand-primary btn-sm d-inline-flex align-items-center gap-1 shadow-sm">
                                    <i class="bi bi-bar-chart"></i> Results (<?php echo (int)$quiz['total_attempts']; ?>)
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

    <?php endif; ?>

<?php else: ?>

    <!-- ════════════════════════════════════════════════════════════════════
         OVERVIEW MODE (No section_subject_id)
         ════════════════════════════════════════════════════════════════════ -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0" style="font-size:.82rem;">
            <li class="breadcrumb-item"><a href="lms" class="text-decoration-none text-brand-primary"><i class="bi bi-journal-bookmark-fill me-1"></i>LMS Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">Quizzes &amp; Exams</li>
        </ol>
    </nav>

    <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="m-0 text-navy-alt">Quizzes &amp; Examinations Overview</h3>
            <p class="text-muted small m-0">Select an assigned subject to configure quizzes, build questions, and view student exam attempts.</p>
        </div>
        <span class="badge bg-secondary-subtle text-brand-primary border border-secondary px-2.5 py-1 text-uppercase fw-semibold" style="font-size: 0.7rem;">
            <?php echo count($allSubjects); ?> Subject<?php echo count($allSubjects) !== 1 ? 's' : ''; ?>
        </span>
    </div>

    <div class="card card-premium shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
        <div class="card-header card-header-premium d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <h5 class="card-title m-0 fw-semibold text-navy-alt">
                <i class="bi bi-patch-question me-1.5 text-brand-primary"></i>
                Assigned Subjects
            </h5>
            <span class="text-muted small"><?php echo count($allSubjects); ?> Available</span>
        </div>
        <div class="card-body card-body-premium p-4">
            <?php if (empty($allSubjects)): ?>
                <div class="text-center py-5">
                    <div class="mb-3">
                        <span class="d-inline-flex align-items-center justify-content-center bg-secondary-subtle text-brand-primary rounded-circle" style="width: 56px; height: 56px; font-size: 1.5rem;">
                            <i class="bi bi-journal-x"></i>
                        </span>
                    </div>
                    <h5 class="text-navy-alt fw-bold mb-1">No subjects assigned</h5>
                    <p class="text-muted small mb-3">You do not have any active subject assignments for the current academic term.</p>
                    <a href="my_classes" class="btn btn-outline-secondary btn-sm"><i class="bi bi-door-open me-1"></i>My Classes</a>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($allSubjects as $s):
                        $sid     = (int)$s['section_subject_id'];
                        $total   = (int)$s['quiz_count'];
                        $pub     = (int)$s['published_count'];
                        $attCount= (int)$s['attempt_count'];
                        $colClass = count($allSubjects) === 1 ? 'col-12' : (count($allSubjects) === 2 ? 'col-12 col-md-6' : 'col-12 col-md-6 col-xl-4');
                    ?>
                    <div class="<?php echo $colClass; ?>">
                        <div class="card h-100 shadow-sm border" style="border-radius: 10px; border-color: #e2e8f0; background: #ffffff;">
                            <div class="card-body p-3.5 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <span class="badge bg-secondary-subtle text-brand-primary border fw-semibold px-2 py-1" style="font-size: 0.72rem;">
                                            <?php echo htmlspecialchars($s['subject_code']); ?>
                                        </span>
                                        <span class="badge bg-light text-navy-alt border px-2 py-1 fw-semibold" style="font-size: 0.7rem;">
                                            <?php echo $total; ?> Quizzes
                                        </span>
                                    </div>
                                    <h6 class="fw-bold text-navy-alt mb-1 text-truncate" title="<?php echo htmlspecialchars($s['subject_name']); ?>">
                                        <?php echo htmlspecialchars($s['subject_name']); ?>
                                    </h6>
                                    <div class="text-muted small mb-2 text-truncate" style="font-size: 0.78rem;">
                                        <?php echo htmlspecialchars($s['section_name']); ?>
                                        <?php if (!empty($s['year_level'])): ?> &middot; <?php echo htmlspecialchars($s['year_level']); ?><?php endif; ?>
                                    </div>
                                </div>
                                <div class="pt-3 border-top d-flex justify-content-between align-items-center text-muted small" style="font-size: 0.75rem; border-color: #f1f5f9 !important;">
                                    <div class="d-flex gap-2">
                                        <span class="text-success"><i class="bi bi-eye me-1"></i><?php echo $pub; ?> live</span>
                                        <span class="text-muted"><i class="bi bi-people me-1"></i><?php echo $attCount; ?> attempts</span>
                                    </div>
                                    <a href="lms_quizzes?section_subject_id=<?php echo $sid; ?>" class="btn btn-sm btn-brand-primary d-inline-flex align-items-center gap-1 shadow-sm text-nowrap">
                                        <i class="bi bi-patch-question"></i> Manage
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════
     MODALS SECTION
     ════════════════════════════════════════════════════════════════════ -->

<?php if ($subjectMode): ?>

    <!-- ── MODAL: CREATE QUIZ ─────────────────────────────────────────── -->
    <div class="modal fade" id="modal-create-quiz" tabindex="-1" aria-labelledby="modalCreateQuizTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form action="../actions/lms_teacher_quiz_actions.php" method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="create_quiz">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalCreateQuizTitle"><i class="bi bi-patch-question me-2 text-brand-primary"></i>Create New Quiz</h5>
                    <button type="button" class="btn-close" data-bs-toggle="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="create-quiz-title">Quiz Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="create-quiz-title" name="title" required maxlength="150" placeholder="e.g., Preliminary Exam — Navigation Basics">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="create-quiz-instructions">Instructions</label>
                        <textarea class="form-control" id="create-quiz-instructions" name="instructions" rows="3" placeholder="Provide instructions, guidelines, and rules for students taking this quiz..."></textarea>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="create-quiz-time">Time Limit (Minutes) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="create-quiz-time" name="time_limit_minutes" min="1" max="600" value="30" required>
                            <div class="form-text">e.g., 30 or 60 minutes.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="create-quiz-attempts">Allowed Attempts <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="create-quiz-attempts" name="allowed_attempts" min="1" max="10" value="1" required>
                            <div class="form-text">1 for exam, or 2+ for practice.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="create-quiz-passing">Passing Score (Points)</label>
                            <input type="number" step="0.01" class="form-control" id="create-quiz-passing" name="passing_score" min="0" placeholder="e.g., 15.00">
                            <div class="form-text">Minimum score to pass (optional).</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="create-quiz-opens">Opens At</label>
                            <input type="datetime-local" class="form-control" id="create-quiz-opens" name="opens_at">
                            <div class="form-text">Leave blank to open immediately.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="create-quiz-closes">Closes At</label>
                            <input type="datetime-local" class="form-control" id="create-quiz-closes" name="closes_at">
                            <div class="form-text">Leave blank for no closing deadline.</div>
                        </div>
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="create-quiz-publish" name="is_published" value="1">
                        <label class="form-check-label fw-semibold" for="create-quiz-publish">Publish immediately to enrolled students</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary btn-sm"><i class="bi bi-check2-circle me-1"></i>Create Quiz</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: EDIT QUIZ ───────────────────────────────────────────── -->
    <div class="modal fade" id="modal-edit-quiz" tabindex="-1" aria-labelledby="modalEditQuizTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form action="../actions/lms_teacher_quiz_actions.php" method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="edit_quiz">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <input type="hidden" name="quiz_id" id="edit-quiz-id">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalEditQuizTitle"><i class="bi bi-gear me-2 text-brand-primary"></i>Edit Quiz Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="edit-quiz-title">Quiz Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit-quiz-title" name="title" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="edit-quiz-instructions">Instructions</label>
                        <textarea class="form-control" id="edit-quiz-instructions" name="instructions" rows="3"></textarea>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="edit-quiz-time">Time Limit (Minutes) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit-quiz-time" name="time_limit_minutes" min="1" max="600" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="edit-quiz-attempts">Allowed Attempts <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit-quiz-attempts" name="allowed_attempts" min="1" max="10" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="edit-quiz-passing">Passing Score (Points)</label>
                            <input type="number" step="0.01" class="form-control" id="edit-quiz-passing" name="passing_score" min="0">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="edit-quiz-opens">Opens At</label>
                            <input type="datetime-local" class="form-control" id="edit-quiz-opens" name="opens_at">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="edit-quiz-closes">Closes At</label>
                            <input type="datetime-local" class="form-control" id="edit-quiz-closes" name="closes_at">
                        </div>
                    </div>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="edit-quiz-publish" name="is_published" value="1">
                        <label class="form-check-label fw-semibold" for="edit-quiz-publish">Published to students</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary btn-sm"><i class="bi bi-check2-circle me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: DELETE QUIZ CONFIRM ─────────────────────────────────── -->
    <div class="modal fade" id="modal-delete-quiz" tabindex="-1" aria-labelledby="modalDelQuizTitle" aria-hidden="true">
        <div class="modal-dialog">
            <form action="../actions/lms_teacher_quiz_actions.php" method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="delete_quiz">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <input type="hidden" name="quiz_id" id="delete-quiz-id">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="modalDelQuizTitle"><i class="bi bi-exclamation-triangle me-2"></i>Delete Quiz</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this quiz?</p>
                    <p class="fw-bold text-navy-alt mb-2" id="delete-quiz-title"></p>
                    <div class="alert alert-danger small mb-0">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        This will permanently delete all questions, answer choices, and student test attempts/scores recorded for this quiz.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete Quiz</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: ADD QUESTION ────────────────────────────────────────── -->
    <?php if ($builderQuiz): ?>
    <div class="modal fade" id="modal-add-question" tabindex="-1" aria-labelledby="modalAddQuestionTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form action="../actions/lms_teacher_quiz_actions.php" method="post" class="modal-content" id="form-add-question">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="add_question">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <input type="hidden" name="quiz_id" value="<?php echo $builderQuiz['id']; ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalAddQuestionTitle"><i class="bi bi-plus-circle me-2 text-brand-primary"></i>Add Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold" for="add-q-type">Question Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="add-q-type" name="question_type" onchange="switchAddQuestionType(this.value)">
                                <option value="multiple_choice" selected>Multiple Choice (Select one correct answer)</option>
                                <option value="true_false">True or False</option>
                                <option value="identification">Identification (Fill in the blank / Exact word)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="add-q-points">Points <span class="text-danger">*</span></label>
                            <input type="number" step="0.25" class="form-control" id="add-q-points" name="points" value="1.00" min="0.25" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="add-q-prompt">Question Prompt / Statement <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="add-q-prompt" name="prompt" rows="3" required placeholder="Type the question or problem statement here..."></textarea>
                    </div>

                    <!-- CONTAINER: Multiple Choice -->
                    <div id="add-container-mc">
                        <label class="form-label fw-semibold d-flex justify-content-between align-items-center">
                            <span>Answer Choices <span class="text-danger">*</span></span>
                            <span class="small text-muted">Select the radio button next to the correct answer</span>
                        </label>
                        <div id="mc-choices-list" class="vstack gap-2 mb-2">
                            <div class="input-group">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0" type="radio" name="correct_choice_index" value="0" checked title="Mark as correct">
                                </div>
                                <span class="input-group-text fw-bold">A</span>
                                <input type="text" class="form-control" name="choices[]" placeholder="Choice text..." required>
                            </div>
                            <div class="input-group">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0" type="radio" name="correct_choice_index" value="1" title="Mark as correct">
                                </div>
                                <span class="input-group-text fw-bold">B</span>
                                <input type="text" class="form-control" name="choices[]" placeholder="Choice text..." required>
                            </div>
                            <div class="input-group">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0" type="radio" name="correct_choice_index" value="2" title="Mark as correct">
                                </div>
                                <span class="input-group-text fw-bold">C</span>
                                <input type="text" class="form-control" name="choices[]" placeholder="Choice text...">
                            </div>
                            <div class="input-group">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0" type="radio" name="correct_choice_index" value="3" title="Mark as correct">
                                </div>
                                <span class="input-group-text fw-bold">D</span>
                                <input type="text" class="form-control" name="choices[]" placeholder="Choice text...">
                            </div>
                        </div>
                    </div>

                    <!-- CONTAINER: True / False -->
                    <div id="add-container-tf" class="d-none">
                        <label class="form-label fw-semibold">Correct Answer <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4 p-3 border rounded bg-light">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="correct_tf" id="tf-add-true" value="true" checked>
                                <label class="form-check-label fw-semibold" for="tf-add-true">True</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="correct_tf" id="tf-add-false" value="false">
                                <label class="form-check-label fw-semibold" for="tf-add-false">False</label>
                            </div>
                        </div>
                    </div>

                    <!-- CONTAINER: Identification -->
                    <div id="add-container-id" class="d-none">
                        <label class="form-label fw-semibold" for="add-q-correct-answer">Correct Answer Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add-q-correct-answer" name="correct_answer" placeholder="Exact expected answer word or phrase...">
                        <div class="form-text">Case-insensitive. Whitespace will be trimmed during automated student answer evaluation.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Save Question</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: EDIT QUESTION ───────────────────────────────────────── -->
    <div class="modal fade" id="modal-edit-question" tabindex="-1" aria-labelledby="modalEditQuestionTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form action="../actions/lms_teacher_quiz_actions.php" method="post" class="modal-content" id="form-edit-question">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="edit_question">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <input type="hidden" name="quiz_id" value="<?php echo $builderQuiz['id']; ?>">
                <input type="hidden" name="question_id" id="edit-q-id">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalEditQuestionTitle"><i class="bi bi-pencil me-2 text-brand-primary"></i>Edit Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Question Type</label>
                            <input type="text" class="form-control-plaintext fw-bold text-navy-alt" id="edit-q-type-label" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" for="edit-q-points">Points <span class="text-danger">*</span></label>
                            <input type="number" step="0.25" class="form-control" id="edit-q-points" name="points" min="0.25" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="edit-q-prompt">Question Prompt / Statement <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="edit-q-prompt" name="prompt" rows="3" required></textarea>
                    </div>

                    <!-- Multiple Choice Choices Editor -->
                    <div id="edit-container-mc" class="d-none">
                        <label class="form-label fw-semibold d-flex justify-content-between align-items-center">
                            <span>Answer Choices <span class="text-danger">*</span></span>
                            <span class="small text-muted">Select the radio button next to the correct answer</span>
                        </label>
                        <div id="edit-mc-choices-list" class="vstack gap-2 mb-2"></div>
                    </div>

                    <!-- True/False Editor -->
                    <div id="edit-container-tf" class="d-none">
                        <label class="form-label fw-semibold">Correct Answer <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4 p-3 border rounded bg-light">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="correct_tf" id="tf-edit-true" value="true">
                                <label class="form-check-label fw-semibold" for="tf-edit-true">True</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="correct_tf" id="tf-edit-false" value="false">
                                <label class="form-check-label fw-semibold" for="tf-edit-false">False</label>
                            </div>
                        </div>
                    </div>

                    <!-- Identification Editor -->
                    <div id="edit-container-id" class="d-none">
                        <label class="form-label fw-semibold" for="edit-q-correct-answer">Correct Answer Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit-q-correct-answer" name="correct_answer">
                        <div class="form-text">Case-insensitive. Whitespace will be trimmed during automated student answer evaluation.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-brand-primary btn-sm"><i class="bi bi-check2-circle me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── MODAL: DELETE QUESTION CONFIRM ─────────────────────────────── -->
    <div class="modal fade" id="modal-delete-question" tabindex="-1" aria-labelledby="modalDelQTitle" aria-hidden="true">
        <div class="modal-dialog">
            <form action="../actions/lms_teacher_quiz_actions.php" method="post" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ensureCsrfToken()); ?>">
                <input type="hidden" name="action" value="delete_question">
                <input type="hidden" name="section_subject_id" value="<?php echo $sectionSubjectId; ?>">
                <input type="hidden" name="quiz_id" id="delete-q-quiz-id">
                <input type="hidden" name="question_id" id="delete-q-id">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger" id="modalDelQTitle"><i class="bi bi-trash me-2"></i>Delete Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Are you sure you want to permanently delete this question and its answer choices?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete Question</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── MODAL: REVIEW STUDENT RESPONSES ────────────────────────────── -->
    <?php if ($resultsQuiz): ?>
    <div class="modal fade" id="modal-responses" tabindex="-1" aria-labelledby="modalResponsesTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="modalResponsesTitle"><i class="bi bi-journal-text me-2 text-brand-primary"></i>Student Quiz Responses</h5>
                        <p class="text-muted small mb-0" id="responses-student-subtitle"></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" id="responses-body-content">
                    <!-- Populated dynamically via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════
     SCRIPTS SECTION
     ════════════════════════════════════════════════════════════════════ -->
<script>
// ── AJAX Publish Toggle ────────────────────────────────────────────────────────
function toggleQuizPublish(quizId, checkbox) {
    const origState = !checkbox.checked;
    const label = document.getElementById('pub-label-' + quizId);
    if (label) label.textContent = checkbox.checked ? 'Live' : 'Hidden';

    const fd = new FormData();
    fd.append('csrf_token', '<?php echo htmlspecialchars(ensureCsrfToken()); ?>');
    fd.append('action', 'toggle_publish');
    fd.append('quiz_id', quizId);

    fetch('../actions/lms_teacher_quiz_actions.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (!data.ok) {
            checkbox.checked = origState;
            if (label) label.textContent = origState ? 'Live' : 'Hidden';
            alert(data.error || 'Failed to update quiz publish status.');
        }
    })
    .catch(() => {
        checkbox.checked = origState;
        if (label) label.textContent = origState ? 'Live' : 'Hidden';
        alert('A network error occurred while updating the quiz status.');
    });
}

// ── Open Edit Quiz Modal ───────────────────────────────────────────────────────
function openEditQuizModal(data) {
    document.getElementById('edit-quiz-id').value           = data.id;
    document.getElementById('edit-quiz-title').value        = data.title;
    document.getElementById('edit-quiz-instructions').value = data.instructions;
    document.getElementById('edit-quiz-time').value         = data.time_limit_minutes;
    document.getElementById('edit-quiz-attempts').value     = data.allowed_attempts;
    document.getElementById('edit-quiz-passing').value      = data.passing_score !== null ? data.passing_score : '';
    document.getElementById('edit-quiz-opens').value        = data.opens_at || '';
    document.getElementById('edit-quiz-closes').value       = data.closes_at || '';
    document.getElementById('edit-quiz-publish').checked    = parseInt(data.is_published) === 1;

    new bootstrap.Modal(document.getElementById('modal-edit-quiz')).show();
}

// ── Open Delete Quiz Modal ─────────────────────────────────────────────────────
function openDeleteQuizModal(quizId, quizTitle) {
    document.getElementById('delete-quiz-id').value = quizId;
    document.getElementById('delete-quiz-title').textContent = quizTitle;
    new bootstrap.Modal(document.getElementById('modal-delete-quiz')).show();
}

// ── Switch Add Question Type ───────────────────────────────────────────────────
function switchAddQuestionType(type) {
    const mc = document.getElementById('add-container-mc');
    const tf = document.getElementById('add-container-tf');
    const id = document.getElementById('add-container-id');

    if (mc) mc.classList.toggle('d-none', type !== 'multiple_choice');
    if (tf) tf.classList.toggle('d-none', type !== 'true_false');
    if (id) id.classList.toggle('d-none', type !== 'identification');

    const promptAns = document.getElementById('add-q-correct-answer');
    if (promptAns) {
        if (type === 'identification') {
            promptAns.setAttribute('required', 'required');
        } else {
            promptAns.removeAttribute('required');
        }
    }
}

// ── Open Edit Question Modal ───────────────────────────────────────────────────
function openEditQuestionModal(data) {
    document.getElementById('edit-q-id').value     = data.id;
    document.getElementById('edit-q-prompt').value = data.prompt;
    document.getElementById('edit-q-points').value = data.points;

    const typeLabels = {
        'multiple_choice': 'Multiple Choice',
        'true_false': 'True / False',
        'identification': 'Identification'
    };
    document.getElementById('edit-q-type-label').value = typeLabels[data.type] || data.type;

    const mc = document.getElementById('edit-container-mc');
    const tf = document.getElementById('edit-container-tf');
    const id = document.getElementById('edit-container-id');

    mc.classList.toggle('d-none', data.type !== 'multiple_choice');
    tf.classList.toggle('d-none', data.type !== 'true_false');
    id.classList.toggle('d-none', data.type !== 'identification');

    if (data.type === 'multiple_choice') {
        const list = document.getElementById('edit-mc-choices-list');
        list.innerHTML = '';
        const choices = data.choices || [];
        choices.forEach((c, idx) => {
            const letter = String.fromCharCode(65 + idx);
            const isChecked = parseInt(c.is_correct) === 1 ? 'checked' : '';
            const row = document.createElement('div');
            row.className = 'input-group';
            row.innerHTML = `
                <div class="input-group-text">
                    <input class="form-check-input mt-0" type="radio" name="correct_choice_index" value="${idx}" ${isChecked} title="Mark as correct">
                </div>
                <span class="input-group-text fw-bold">${letter}</span>
                <input type="text" class="form-control" name="choices[]" value="${c.choice_text.replace(/"/g, '&quot;')}" required>
            `;
            list.appendChild(row);
        });
    } else if (data.type === 'true_false') {
        const choices = data.choices || [];
        let isTrueCorrect = false;
        choices.forEach(c => {
            if (c.choice_text.toLowerCase() === 'true' && parseInt(c.is_correct) === 1) isTrueCorrect = true;
        });
        document.getElementById('tf-edit-true').checked  = isTrueCorrect;
        document.getElementById('tf-edit-false').checked = !isTrueCorrect;
    } else if (data.type === 'identification') {
        document.getElementById('edit-q-correct-answer').value = data.correct_answer || '';
    }

    new bootstrap.Modal(document.getElementById('modal-edit-question')).show();
}

// ── Open Delete Question Modal ─────────────────────────────────────────────────
function openDeleteQuestionModal(qId, quizId, ssId) {
    document.getElementById('delete-q-id').value      = qId;
    document.getElementById('delete-q-quiz-id').value = quizId;
    new bootstrap.Modal(document.getElementById('modal-delete-question')).show();
}

// ── Open Student Responses Modal ───────────────────────────────────────────────
<?php if ($resultsQuiz): ?>
const attemptResponsesMap = <?php echo json_encode($attemptResponses, JSON_UNESCAPED_SLASHES); ?>;

function openResponsesModal(attemptId, studentName, attemptNumber) {
    document.getElementById('responses-student-subtitle').textContent =
        studentName + ' · Attempt #' + attemptNumber;

    const container = document.getElementById('responses-body-content');
    container.innerHTML = '';

    const responses = attemptResponsesMap[attemptId] || [];
    if (responses.length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-4">No recorded answers found for this attempt.</p>';
    } else {
        responses.forEach((resp, idx) => {
            const card = document.createElement('div');
            card.className = 'card border rounded mb-3';

            let isCorrect = false;
            let studentAnsText = '';
            let correctAnsText = '';

            if (resp.question_type === 'identification') {
                studentAnsText = resp.answer_text ? resp.answer_text.trim() : '(No answer given)';
                correctAnsText = resp.correct_answer ? resp.correct_answer.trim() : '';
                isCorrect = studentAnsText.toLowerCase() === correctAnsText.toLowerCase();
            } else {
                studentAnsText = resp.student_choice_text ? resp.student_choice_text : '(No choice selected)';
                correctAnsText = resp.correct_choice_text ? resp.correct_choice_text : '';
                isCorrect = resp.choice_id && (studentAnsText === correctAnsText);
            }

            const statusBadge = isCorrect
                ? `<span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Correct (${resp.points} pts)</span>`
                : `<span class="badge text-bg-danger"><i class="bi bi-x-circle me-1"></i>Incorrect (0 / ${resp.points} pts)</span>`;

            card.innerHTML = `
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="badge bg-light text-muted border">Question #${idx + 1}</span>
                        ${statusBadge}
                    </div>
                    <div class="fw-semibold text-dark mb-2">${resp.prompt}</div>
                    <div class="p-2 px-3 rounded mb-2 ${isCorrect ? 'bg-success-subtle text-success-emphasis border border-success' : 'bg-danger-subtle text-danger-emphasis border border-danger'}" style="font-size:.88rem;">
                        <span class="small text-muted d-block">Student's answer:</span>
                        <strong>${studentAnsText}</strong>
                    </div>
                    ${!isCorrect ? `
                    <div class="p-2 px-3 rounded bg-light border text-muted" style="font-size:.85rem;">
                        <span class="small d-block">Expected correct answer:</span>
                        <strong class="text-success">${correctAnsText}</strong>
                    </div>` : ''}
                </div>
            `;
            container.appendChild(card);
        });
    }

    new bootstrap.Modal(document.getElementById('modal-responses')).show();
}
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>
