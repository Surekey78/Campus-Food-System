<?php
/**
 * public/admin/categories.php  —  manage menu categories
 * A category that still has items cannot be deleted (the foreign key
 * is ON DELETE RESTRICT), which protects existing menu rows.
 */
require_once __DIR__ . '/../../includes/functions.php';

require_admin();

$editing = null;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = $_POST['action'] ?? 'save';
    $id     = (int) ($_POST['id'] ?? 0);

    // ------------------------------------------------------------ delete
    if ($action === 'delete') {
        $inUse = (int) db_val('SELECT COUNT(*) FROM menu_items WHERE category_id = ?', [$id], 0);

        if ($inUse > 0) {
            flash("Cannot delete: $inUse item(s) still belong to that category. Move them first.", 'error');
        } else {
            db_exec('DELETE FROM categories WHERE id = ?', [$id]);
            flash('Category deleted.', 'warn');
        }

        redirect('/admin/categories.php');
    }

    // -------------------------------------------------------------- save
    $name  = trim((string) ($_POST['name'] ?? ''));
    $icon  = icon_clean($_POST['icon'] ?? null, 'plate');
    $order = (int) ($_POST['sort_order'] ?? 0);
    $on    = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

    if ($name === '') {
        $errors[] = 'The category name is required.';
    } else {
        $clash = db_one(
            'SELECT id FROM categories WHERE name = ? AND id <> ?',
            [$name, $id]
        );
        if ($clash) {
            $errors[] = 'A category with that name already exists.';
        }
    }

    if ($errors === []) {
        if ($id > 0) {
            db_exec(
                'UPDATE categories SET name = ?, icon = ?, sort_order = ?, is_active = ? WHERE id = ?',
                [$name, $icon, $order, $on, $id]
            );
            flash('“' . $name . '” updated.');
        } else {
            db_exec(
                'INSERT INTO categories (name, icon, sort_order, is_active) VALUES (?, ?, ?, ?)',
                [$name, $icon, $order, $on]
            );
            flash('“' . $name . '” added.');
        }

        redirect('/admin/categories.php');
    }

    $editing = ['id' => $id, 'name' => $name, 'icon' => $icon,
                'sort_order' => $order, 'is_active' => $on];
}

if ($editing === null && isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
    $editing = db_one('SELECT * FROM categories WHERE id = ?', [(int) $_GET['edit']]);

    if (!$editing) {
        flash('That category no longer exists.', 'error');
        redirect('/admin/categories.php');
    }
}

$categories = db_all(
    'SELECT c.*, COUNT(m.id) AS item_count
       FROM categories c
       LEFT JOIN menu_items m ON m.category_id = c.id
      GROUP BY c.id, c.name, c.icon, c.sort_order, c.is_active
      ORDER BY c.sort_order, c.name'
);

$pageTitle   = 'Categories';
$activeAdmin = 'categories';
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Categories</h1>
        <p>Grouping and ordering of the sections shown on the student menu.</p>
    </div>
    <?php if ($editing): ?>
        <a class="btn btn-ghost" href="<?= e(url('/admin/categories.php')) ?>">+ Add a new category</a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/admin_nav.php'; ?>

<div class="two-col">

    <div class="card">
        <div class="table-wrap">
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="num">Items</th>
                        <th>Visible</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td class="cell-main">
                            <span class="small muted">#<?= (int) $category['sort_order'] ?></span>
                            <strong><?= item_icon($category['icon'], 16) ?> <?= e($category['name']) ?></strong>
                        </td>
                        <td class="num nowrap" data-label="Items"><?= (int) $category['item_count'] ?></td>
                        <td data-label="Visible">
                            <?php if ((int) $category['is_active'] === 1): ?>
                                <span class="badge badge-completed">Shown</span>
                            <?php else: ?>
                                <span class="badge badge-off">Hidden</span>
                            <?php endif; ?>
                        </td>
                        <td class="num nowrap">
                            <a class="btn btn-ghost btn-sm"
                               href="<?= e(url('/admin/categories.php?edit=' . (int) $category['id'])) ?>">Edit</a>
                            <form method="post" action="<?= e(url('/admin/categories.php')) ?>" data-dirty-check style="display:inline;" data-confirm="Delete “<?= e($category['name']) ?>”?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small muted" style="margin:14px 0 0;">
            Lower sort numbers appear first on the student menu.
        </p>
    </div>

    <div class="card">
        <h2><?= $editing ? 'Edit category' : 'Add a category' ?></h2>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endforeach; ?>

        <?php
            $v = $editing ?? ['id' => 0, 'name' => '', 'icon' => 'plate',
                              'sort_order' => (count($categories) + 1) * 10, 'is_active' => 1];
        ?>

        <form method="post" action="<?= e(url('/admin/categories.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">

            <div class="field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?= e($v['name']) ?>"
                       maxlength="60" required placeholder="Rice Meals">
            </div>

              <?= icon_picker('icon', $v['icon'], 'Icon') ?>

              <div class="grid-2">
                  <div class="field">
                      <label for="sort_order">Sort order</label>
                      <input type="number" id="sort_order" name="sort_order"
                             value="<?= (int) $v['sort_order'] ?>">
                  </div>
              </div>

            <div class="field">
                <label for="is_active">Visibility</label>
                <select id="is_active" name="is_active">
                    <option value="1" <?= (int) $v['is_active'] === 1 ? 'selected' : '' ?>>Shown to students</option>
                    <option value="0" <?= (int) $v['is_active'] === 0 ? 'selected' : '' ?>>Hidden</option>
                </select>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    <?= $editing ? 'Save changes' : 'Add category' ?>
                </button>
                <?php if ($editing): ?>
                    <a class="btn btn-ghost" href="<?= e(url('/admin/categories.php')) ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
