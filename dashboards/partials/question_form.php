<?php
/**
 * Shared create/edit form for one question (quiz question page and bank).
 *
 * Expects:
 *   $form          values (question_form_defaults() / question_form_values())
 *   $catalog       teacher_catalog() result (subjects with topics)
 *   $form_action   'add_question' or 'update_question'
 *   $form_hidden   extra hidden fields, name => value
 *   $form_submit   submit button label
 *   $form_errors   list of validation messages (optional)
 *   $form_cancel   URL for a Cancel link (optional)
 */
$form_errors = $form_errors ?? [];
$opts = $form['options'];
while (count($opts) < 2) {
    $opts[] = ['text' => '', 'correct' => false];
}
?>
<form method="POST" class="question-form" id="question-form" novalidate><?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="<?php echo e($form_action); ?>">
    <?php foreach ($form_hidden as $name => $value): ?>
        <input type="hidden" name="<?php echo e($name); ?>" value="<?php echo e($value); ?>">
    <?php endforeach; ?>

    <?php if ($form_errors): ?>
        <div class="alert alert-error" role="alert">
            <div><strong>Please fix the following:</strong>
                <ul class="import-skipped"><?php foreach ($form_errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-grid">
        <div class="form-field">
            <label for="qf-type">Question type</label>
            <select id="qf-type" name="type" data-question-type>
                <?php foreach (QUESTION_TYPES as $value => $label): ?>
                    <option value="<?php echo e($value); ?>" <?php echo $form['type'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="qf-points">Points</label>
            <input type="number" id="qf-points" name="points" value="<?php echo e($form['points']); ?>" min="0.25" max="999.99" step="0.25" required>
        </div>
        <div class="form-field">
            <label for="qf-difficulty">Difficulty</label>
            <select id="qf-difficulty" name="difficulty">
                <option value="">Not set</option>
                <?php foreach (DIFFICULTIES as $value => $label): ?>
                    <option value="<?php echo e($value); ?>" <?php echo $form['difficulty'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="qf-topic">Subject / topic</label>
            <select id="qf-topic" name="topic_id">
                <option value="0">No topic</option>
                <?php foreach ($catalog as $subject): ?>
                    <?php if ($subject['topics']): ?>
                        <optgroup label="<?php echo e($subject['name']); ?>">
                            <?php foreach ($subject['topics'] as $t): ?>
                                <option value="<?php echo (int) $t['id']; ?>" <?php echo (int) $form['topic_id'] === (int) $t['id'] ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-field">
        <label for="qf-text">Question</label>
        <textarea id="qf-text" name="question_text" rows="3" maxlength="<?php echo QUESTION_TEXT_MAX; ?>" required><?php echo e($form['question_text']); ?></textarea>
        <p class="helptext" data-for-type="fill_blank">Mark each blank with three underscores, e.g. "The capital of France is ___."</p>
    </div>

    <!-- Multiple choice / multiple select -->
    <fieldset class="section-picker" data-for-type="mcq mcq_multi">
        <legend data-for-type="mcq">Options (select the correct one)</legend>
        <legend data-for-type="mcq_multi">Options (tick every correct one)</legend>
        <div id="qf-options">
            <?php foreach ($opts as $i => $o): ?>
                <div class="option-row">
                    <input type="radio" name="correct_option" value="<?php echo $i; ?>" aria-label="Option <?php echo $i + 1; ?> is correct" data-for-type="mcq" <?php echo $o['correct'] ? 'checked' : ''; ?>>
                    <input type="checkbox" name="correct[]" value="<?php echo $i; ?>" aria-label="Option <?php echo $i + 1; ?> is correct" data-for-type="mcq_multi" <?php echo $o['correct'] ? 'checked' : ''; ?>>
                    <input type="text" name="options[]" value="<?php echo e($o['text']); ?>" placeholder="Option <?php echo $i + 1; ?>" maxlength="<?php echo OPTION_TEXT_MAX; ?>" aria-label="Option <?php echo $i + 1; ?>">
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-outline btn-sm" data-add-option>+ Add option</button>
        <div class="form-field" data-for-type="mcq_multi" style="margin-top:12px">
            <label for="qf-scoring">Scoring</label>
            <select id="qf-scoring" name="scoring">
                <?php foreach (MULTI_SCORING as $value => $label): ?>
                    <option value="<?php echo e($value); ?>" <?php echo $form['scoring'] === $value ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <!-- True / False -->
    <fieldset class="section-picker" data-for-type="true_false">
        <legend>Correct answer</legend>
        <div class="check-row">
            <label class="form-check"><input type="radio" name="tf_answer" value="true" <?php echo $form['tf_answer'] === 'true' ? 'checked' : ''; ?>> True</label>
            <label class="form-check"><input type="radio" name="tf_answer" value="false" <?php echo $form['tf_answer'] === 'false' ? 'checked' : ''; ?>> False</label>
        </div>
    </fieldset>

    <!-- Short answer -->
    <div data-for-type="short_answer">
        <div class="form-field">
            <label for="qf-accepted">Accepted answers (one per line)</label>
            <textarea id="qf-accepted" name="accepted" rows="3" placeholder="Oxygen&#10;O2"><?php echo e($form['accepted']); ?></textarea>
            <p class="helptext">Leave empty to grade every response by hand. Extra spaces are always ignored.</p>
        </div>
    </div>

    <!-- Fill in the blanks -->
    <div data-for-type="fill_blank">
        <div class="form-field">
            <label for="qf-blanks">Accepted answers per blank (one line per blank, alternatives separated by |)</label>
            <textarea id="qf-blanks" name="blank_answers" rows="3" placeholder="Paris&#10;France | French Republic"><?php echo e($form['blank_answers']); ?></textarea>
        </div>
        <div class="check-row">
            <label class="form-check"><input type="checkbox" name="partial" value="1" <?php echo $form['partial'] ? 'checked' : ''; ?>> Partial credit per correct blank</label>
        </div>
    </div>

    <!-- Matching rules for typed answers -->
    <div class="check-row" data-for-type="short_answer fill_blank">
        <label class="form-check"><input type="checkbox" name="case_sensitive" value="1" <?php echo $form['case_sensitive'] ? 'checked' : ''; ?>> Case-sensitive</label>
        <label class="form-check"><input type="checkbox" name="ignore_punctuation" value="1" <?php echo $form['ignore_punctuation'] ? 'checked' : ''; ?>> Ignore punctuation</label>
    </div>

    <!-- Numerical -->
    <div class="form-grid" data-for-type="numeric">
        <div class="form-field">
            <label for="qf-value">Correct value</label>
            <input type="text" inputmode="decimal" id="qf-value" name="numeric_value" value="<?php echo e($form['numeric_value']); ?>" placeholder="9.81">
        </div>
        <div class="form-field">
            <label for="qf-tolerance">Tolerance (±)</label>
            <input type="text" inputmode="decimal" id="qf-tolerance" name="tolerance" value="<?php echo e($form['tolerance']); ?>" placeholder="0.05">
        </div>
        <div class="form-field">
            <label for="qf-tol-type">Tolerance is</label>
            <select id="qf-tol-type" name="tolerance_type">
                <option value="absolute" <?php echo $form['tolerance_type'] === 'absolute' ? 'selected' : ''; ?>>An absolute amount</option>
                <option value="percent" <?php echo $form['tolerance_type'] === 'percent' ? 'selected' : ''; ?>>A percentage of the value</option>
            </select>
        </div>
    </div>

    <!-- Essay -->
    <div class="form-field" data-for-type="essay">
        <label for="qf-guidance">Marking guidance for you (optional, never shown to students)</label>
        <textarea id="qf-guidance" name="guidance" rows="3"><?php echo e($form['guidance']); ?></textarea>
        <p class="helptext">Essays are always graded by hand.</p>
    </div>

    <div class="form-field">
        <label for="qf-explanation">Explanation (optional)</label>
        <textarea id="qf-explanation" name="explanation" rows="2" maxlength="<?php echo EXPLANATION_MAX; ?>" placeholder="Shown to students with the correct answer, when you show answers."><?php echo e($form['explanation']); ?></textarea>
    </div>

    <div class="item-actions">
        <button type="submit" class="btn"><?php echo e($form_submit); ?></button>
        <?php if (!empty($form_cancel)): ?><a class="btn btn-outline" href="<?php echo e($form_cancel); ?>">Cancel</a><?php endif; ?>
    </div>
</form>
<script src="<?php echo e($nav_base); ?>../assets/question_form.js" defer></script>
