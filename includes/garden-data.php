<?php
// A member's garden read from the database: plants, collections, reminders, species, likes (CORE-14, CORE-15, CORE-24).
// Each function is one prepared statement returning rows shaped exactly like the stage F mock arrays (same keys, the
// joined columns included), so the pages that read those arrays did not have to change. mock-data.php fills the old
// array names from these functions; later each page will call them itself and mock-data.php goes.

require_once __DIR__ . '/db.php';

/**
 * The member's own plants, private ones included, in the shape of $mock_plants:
 * every plants column, plus scientific_name and common_name (plant_species; a typed custom_species stands in for
 * common_name), collection_name (collections), the soonest reminder's task, interval_days and next_due
 * (care_reminders), and last_logged_at, the newest story entry (or the day the plant was added).
 */
function garden_plants(int $user_id): array
{
    // ROW_NUMBER() numbers each plant's reminders from the soonest; rn = 1 keeps one per plant (MariaDB 10.2+).
    $stmt = db()->prepare(
        'SELECT p.id, p.user_id, p.collection_id, p.species_id, p.custom_species, p.nickname, p.photo, p.acquired_on,
                p.health_status, p.is_public, p.created_at,
                s.scientific_name, COALESCE(s.common_name, p.custom_species) AS common_name,
                c.name AS collection_name,
                r.task, r.interval_days, r.next_due,
                COALESCE((SELECT MAX(h.logged_at) FROM health_logs h WHERE h.plant_id = p.id), p.created_at) AS last_logged_at
         FROM plants p
         LEFT JOIN plant_species s ON s.id = p.species_id
         LEFT JOIN collections c   ON c.id = p.collection_id
         LEFT JOIN (SELECT plant_id, task, interval_days, next_due,
                           ROW_NUMBER() OVER (PARTITION BY plant_id ORDER BY next_due, id) AS rn
                    FROM care_reminders) r ON r.plant_id = p.id AND r.rn = 1
         WHERE p.user_id = :user_id
         ORDER BY p.created_at, p.id'
    );
    $stmt->execute(['user_id' => $user_id]);
    return $stmt->fetchAll();
}

/**
 * Other members' PUBLIC plants, in the shape of $mock_community_plants (no reminders: those are the owner's business).
 * $user_id is the viewer, whose own plants are in garden_plants() instead; null for a visitor, who sees every public
 * plant. A private plant is never returned to anyone but its owner (security rules, CLAUDE.md).
 */
function other_public_plants(?int $user_id): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.user_id, p.collection_id, p.species_id, p.custom_species, p.nickname, p.photo, p.acquired_on,
                p.health_status, p.is_public, p.created_at,
                s.scientific_name, COALESCE(s.common_name, p.custom_species) AS common_name,
                c.name AS collection_name,
                COALESCE((SELECT MAX(h.logged_at) FROM health_logs h WHERE h.plant_id = p.id), p.created_at) AS last_logged_at
         FROM plants p
         LEFT JOIN plant_species s ON s.id = p.species_id
         LEFT JOIN collections c   ON c.id = p.collection_id
         WHERE p.is_public = 1 AND (:user_id IS NULL OR p.user_id <> :user_id2)
         ORDER BY p.created_at, p.id'
    );
    $stmt->execute(['user_id' => $user_id, 'user_id2' => $user_id]);
    return $stmt->fetchAll();
}

/**
 * The member's collections in the shape of $mock_collections: every column, plus plant_count and cover_photos (up to
 * three photos, most liked plant first) for the member's own pages, and public_plant_count and public_cover_photos
 * for their Public Profile, which must not count or show private plants.
 */
function garden_collections(int $user_id): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.user_id, c.name, c.is_public, c.created_at,
                COUNT(p.id) AS plant_count,
                COALESCE(SUM(p.is_public), 0) AS public_plant_count,
                GROUP_CONCAT(p.photo ORDER BY (SELECT COUNT(*) FROM plant_likes l WHERE l.plant_id = p.id) DESC, p.id SEPARATOR "\n") AS photos,
                GROUP_CONCAT(IF(p.is_public = 1, p.photo, NULL) ORDER BY (SELECT COUNT(*) FROM plant_likes l WHERE l.plant_id = p.id) DESC, p.id SEPARATOR "\n") AS public_photos
         FROM collections c
         LEFT JOIN plants p ON p.collection_id = c.id
         WHERE c.user_id = :user_id
         GROUP BY c.id
         ORDER BY c.created_at, c.id'
    );
    $stmt->execute(['user_id' => $user_id]);

    $collections = [];
    foreach ($stmt->fetchAll() as $row) {
        // GROUP_CONCAT gives one text with a line break between photos (or NULL): split it, keep the first three
        $row['cover_photos']        = array_slice(array_filter(explode("\n", (string) $row['photos'])), 0, 3);
        $row['public_cover_photos'] = array_slice(array_filter(explode("\n", (string) $row['public_photos'])), 0, 3);
        $row['plant_count']         = (int) $row['plant_count'];
        $row['public_plant_count']  = (int) $row['public_plant_count'];
        unset($row['photos'], $row['public_photos']);
        $collections[] = $row;
    }
    return $collections;
}

/** Every reminder of the member's plants, soonest first, in the shape of $mock_plant_reminders (every column). */
function garden_reminders(int $user_id): array
{
    $stmt = db()->prepare(
        'SELECT r.id, r.plant_id, r.task, r.remind_time, r.repeat_type, r.repeat_days, r.interval_days, r.last_done, r.next_due
         FROM care_reminders r
         JOIN plants p ON p.id = r.plant_id
         WHERE p.user_id = :user_id
         ORDER BY r.next_due, r.remind_time, r.id'
    );
    $stmt->execute(['user_id' => $user_id]);
    return array_map('reminder_row', $stmt->fetchAll());
}

/**
 * repeat_days arrives from MySQL as one text, "mon,wed" (a SET column); the pages and repeat_words() use a list,
 * ['mon', 'wed'], as the mock rows did.
 */
function reminder_row(array $row): array
{
    $row['repeat_days'] = ($row['repeat_days'] ?? '') === '' ? [] : explode(',', $row['repeat_days']);
    return $row;
}

/**
 * Today's care: the member's reminders due today or overdue, in the shape of $mock_reminders: the reminder, plus the
 * plant's nickname and photo and the species' common name. CURDATE() agrees with PHP's today (db.php sets the zone).
 */
function due_reminders(int $user_id): array
{
    $stmt = db()->prepare(
        'SELECT r.id, r.plant_id, r.task, r.remind_time, r.repeat_type, r.repeat_days, r.interval_days, r.last_done, r.next_due,
                p.nickname, p.photo, COALESCE(s.common_name, p.custom_species) AS common_name
         FROM care_reminders r
         JOIN plants p ON p.id = r.plant_id
         LEFT JOIN plant_species s ON s.id = p.species_id
         WHERE p.user_id = :user_id AND r.next_due <= CURDATE()
         ORDER BY r.next_due, p.nickname'
    );
    $stmt->execute(['user_id' => $user_id]);
    return array_map('reminder_row', $stmt->fetchAll());
}

/** Every species, keyed by id, in the shape of $mock_species (so a plant finds its row with $species[$species_id]). */
function species_list(): array
{
    $rows = db()->query(
        'SELECT id, common_name, scientific_name, photo, light, water, humidity, pet_safe,
                water_interval_days, fertilise_interval_days, notes
         FROM plant_species ORDER BY id'
    )->fetchAll();
    return array_column($rows, null, 'id');
}

/** Likes per plant, as plant id => count, in the shape of $mock_like_counts. Plants without likes are left out. */
function like_counts(): array
{
    return db()->query('SELECT plant_id, COUNT(*) AS likes FROM plant_likes GROUP BY plant_id')
               ->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Every member as other people see them, keyed by id, in the shape of $mock_users: public columns only (never email or
 * phone), the town as location, and plant_count and collection_count of PUBLIC plants and collections only, because
 * these numbers are shown to other people. (Swaps and ratings join in Phase 4, with the exchange.)
 */
function public_members(): array
{
    $rows = db()->query(
        'SELECT u.id, u.username, u.full_name, u.avatar, u.bio, t.name AS location, u.created_at,
                (SELECT COUNT(*) FROM plants p WHERE p.user_id = u.id AND p.is_public = 1)      AS plant_count,
                (SELECT COUNT(*) FROM collections c WHERE c.user_id = u.id AND c.is_public = 1) AS collection_count
         FROM users u LEFT JOIN towns t ON t.id = u.town_id
         ORDER BY u.id'
    )->fetchAll();
    return array_column($rows, null, 'id');
}

// ── One plant's page (CORE-18) ───────────────────────────────────────────────

/**
 * A plant's Story, newest first, in the shape of $mock_health_logs: every health_logs column, plus the AI result an
 * entry came from (prediction_type, predicted_label, confidence; NULL for a manual entry).
 */
function plant_story(int $plant_id): array
{
    $stmt = db()->prepare(
        'SELECT h.id, h.plant_id, h.prediction_id, h.type, h.care_task, h.status, h.source, h.note, h.photo, h.logged_at,
                m.type AS prediction_type, m.predicted_label, m.confidence
         FROM health_logs h
         LEFT JOIN ml_predictions m ON m.id = h.prediction_id
         WHERE h.plant_id = :plant_id
         ORDER BY h.logged_at DESC, h.id DESC'
    );
    $stmt->execute(['plant_id' => $plant_id]);
    return $stmt->fetchAll();
}

/** A plant's extra gallery photos, newest first, in the shape of $mock_plant_photos. */
function plant_photos(int $plant_id): array
{
    $stmt = db()->prepare('SELECT id, plant_id, path, created_at FROM plant_photos WHERE plant_id = :plant_id ORDER BY created_at DESC, id DESC');
    $stmt->execute(['plant_id' => $plant_id]);
    return $stmt->fetchAll();
}

// ── The Landing page (CORE-27) ───────────────────────────────────────────────
// Visitors only (members go to their Dashboard), so everything here is public: public plants, public columns.

/** The live counts: how many members, how many open listings. */
function site_counts(): array
{
    return db()->query(
        "SELECT (SELECT COUNT(*) FROM users) AS member_count,
                (SELECT COUNT(*) FROM exchange_listings WHERE status = 'open') AS open_listing_count"
    )->fetch();
}

/** The newest members, for the avatar row: public columns only. */
function recent_members(int $limit = 4): array
{
    $stmt = db()->prepare('SELECT id, username, full_name, avatar FROM users ORDER BY created_at DESC, id DESC LIMIT :limit');
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);   // LIMIT needs a whole number, not text
    $stmt->execute();
    return $stmt->fetchAll();
}

/** The most liked public plant, with its owner's public columns and like_count; null when there is none. */
function featured_plant(): ?array
{
    $row = db()->query(
        'SELECT p.id, p.user_id, p.nickname, p.photo, p.health_status, p.is_public, s.scientific_name,
                u.username, u.full_name, u.avatar, COUNT(l.id) AS like_count
         FROM plants p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN plant_species s ON s.id = p.species_id
         LEFT JOIN plant_likes l ON l.plant_id = p.id
         WHERE p.is_public = 1
         GROUP BY p.id
         ORDER BY like_count DESC, p.id
         LIMIT 1'
    )->fetch();
    return $row ?: null;
}

/**
 * For the collage's care chip: the public plant with the soonest reminder, as the plant row plus that reminder's
 * task and next_due; null when no public plant has a reminder.
 */
function public_care_sample(): ?array
{
    $row = db()->query(
        'SELECT p.id, p.nickname, p.photo, p.health_status, COALESCE(s.scientific_name, p.custom_species) AS scientific_name,
                r.task, r.next_due
         FROM care_reminders r
         JOIN plants p ON p.id = r.plant_id
         LEFT JOIN plant_species s ON s.id = p.species_id
         WHERE p.is_public = 1
         ORDER BY r.next_due, p.id
         LIMIT 1'
    )->fetch();
    return $row ?: null;
}

/**
 * The newest confident AI result (60% or more) of one type ('species' or 'disease') attached to a public plant, with that plant's nickname
 * and common name, and for a species the common name of the predicted species; null when there is none.
 */
function latest_public_prediction(string $type): ?array
{
    $stmt = db()->prepare(
        'SELECT m.id, m.type, m.predicted_label, m.confidence, m.created_at,
                p.nickname AS plant_nickname, COALESCE(ps.common_name, p.custom_species) AS plant_common_name,
                guess.common_name
         FROM ml_predictions m
         JOIN plants p ON p.id = m.plant_id
         LEFT JOIN plant_species ps    ON ps.id = p.species_id
         LEFT JOIN plant_species guess ON guess.scientific_name = m.predicted_label
         WHERE m.type = :type AND p.is_public = 1 AND m.confidence >= 0.60   -- below 60% is never shown as an answer
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 1'
    );
    $stmt->execute(['type' => $type]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ── Likes (COM-15) ───────────────────────────────────────────────────────────

/** Has this member liked this plant? */
function has_liked(int $user_id, int $plant_id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM plant_likes WHERE plant_id = :plant_id AND user_id = :user_id');
    $stmt->execute(['plant_id' => $plant_id, 'user_id' => $user_id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Like or unlike another member's PUBLIC plant. Your own plant and private plants cannot be liked: the INSERT copies
 * the plant only when both rules hold. UNIQUE (plant_id, user_id) keeps it to one like per member.
 */
function toggle_like(int $user_id, int $plant_id): void
{
    if (has_liked($user_id, $plant_id)) {
        $stmt = db()->prepare('DELETE FROM plant_likes WHERE plant_id = :plant_id AND user_id = :user_id');
        $stmt->execute(['plant_id' => $plant_id, 'user_id' => $user_id]);
        return;
    }
    $stmt = db()->prepare(
        'INSERT IGNORE INTO plant_likes (plant_id, user_id)
         SELECT p.id, :user_id FROM plants p WHERE p.id = :plant_id AND p.is_public = 1 AND p.user_id <> :user_id2'
    );
    $stmt->execute(['user_id' => $user_id, 'plant_id' => $plant_id, 'user_id2' => $user_id]);
}

// ── Collections: saving (CORE-13) ────────────────────────────────────────────
// Every statement carries "AND user_id = :user_id": that is the ownership check. A hand-made request with another
// member's collection or plant id changes nothing, because no row matches.

/**
 * Move some of the member's plants into one of their collections. Only plants that are theirs and in no collection
 * yet move. Returns how many moved. Placeholders are built one per id (:p0, :p1 …): a list cannot be one parameter.
 */
function move_plants_into_collection(int $user_id, int $collection_id, array $plant_ids): int
{
    if (!$plant_ids) {
        return 0;
    }
    $params = ['collection_id' => $collection_id, 'user_id' => $user_id];
    $marks  = [];
    foreach (array_values($plant_ids) as $index => $plant_id) {
        $marks[]              = ':p' . $index;
        $params['p' . $index] = (int) $plant_id;
    }
    $stmt = db()->prepare(
        'UPDATE plants SET collection_id = :collection_id
         WHERE id IN (' . implode(', ', $marks) . ') AND user_id = :user_id AND collection_id IS NULL'
    );
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Create a collection and move the chosen plants into it, in one transaction: both happen, or neither.
 * Returns the new id. A name the member already uses throws PDOException 23000 (UNIQUE (user_id, name)).
 */
function create_collection(int $user_id, string $name, bool $is_public, array $plant_ids): int
{
    db()->beginTransaction();
    try {
        $stmt = db()->prepare('INSERT INTO collections (user_id, name, is_public) VALUES (:user_id, :name, :is_public)');
        $stmt->execute(['user_id' => $user_id, 'name' => $name, 'is_public' => $is_public ? 1 : 0]);
        $collection_id = (int) db()->lastInsertId();
        move_plants_into_collection($user_id, $collection_id, $plant_ids);
        db()->commit();
        return $collection_id;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

/** Delete one of the member's collections. Its plants stay, with no collection (plants.collection_id ON DELETE SET NULL). */
function delete_collection(int $user_id, int $collection_id): bool
{
    $stmt = db()->prepare('DELETE FROM collections WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $collection_id, 'user_id' => $user_id]);
    return $stmt->rowCount() === 1;
}

// ── Plants: saving (CORE-16) ─────────────────────────────────────────────────

/**
 * Add $quantity plants with the same details, in one transaction. Each gets its own row ("Spike", "Spike 2" …), the
 * reminders that were switched on, and the first entry of its Story ("Added to garden.", with the health chosen).
 * $plant: collection_id, species_id, custom_species, nickname, photo, acquired_on, health_status, is_public.
 * $reminders: task => ['interval_days' => 7, 'next_due' => 'Y-m-d'] for water and fertilise.
 * Returns the new ids.
 */
function add_plants(int $user_id, array $plant, int $quantity, array $reminders): array
{
    $ids = [];
    db()->beginTransaction();
    try {
        $insert_plant = db()->prepare(
            'INSERT INTO plants (user_id, collection_id, species_id, custom_species, nickname, photo, acquired_on, health_status, is_public)
             VALUES (:user_id, :collection_id, :species_id, :custom_species, :nickname, :photo, :acquired_on, :health_status, :is_public)'
        );
        $insert_reminder = db()->prepare(
            "INSERT INTO care_reminders (plant_id, task, repeat_type, interval_days, next_due)
             VALUES (:plant_id, :task, 'interval', :interval_days, :next_due)"
        );
        $insert_log = db()->prepare(
            "INSERT INTO health_logs (plant_id, type, status, source, note, photo)
             VALUES (:plant_id, 'milestone', :status, 'manual', 'Added to garden.', :photo)"
        );
        for ($number = 1; $number <= $quantity; $number++) {
            $insert_plant->execute([
                'user_id'        => $user_id,
                'collection_id'  => $plant['collection_id'],
                'species_id'     => $plant['species_id'],
                'custom_species' => $plant['custom_species'],
                // "Spike", "Spike 2", "Spike 3": every plant has its own nickname (plants.nickname is NOT NULL)
                'nickname'       => $number === 1 ? $plant['nickname'] : $plant['nickname'] . ' ' . $number,
                'photo'          => $plant['photo'],
                'acquired_on'    => $plant['acquired_on'],
                'health_status'  => $plant['health_status'],
                'is_public'      => $plant['is_public'],
            ]);
            $plant_id = (int) db()->lastInsertId();
            foreach ($reminders as $task => $reminder) {
                $insert_reminder->execute(['plant_id' => $plant_id, 'task' => $task,
                                           'interval_days' => $reminder['interval_days'], 'next_due' => $reminder['next_due']]);
            }
            $insert_log->execute(['plant_id' => $plant_id, 'status' => $plant['health_status'], 'photo' => $plant['photo']]);
            $ids[] = $plant_id;
        }
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
    return $ids;
}

/**
 * Save the Edit form for one of the member's plants. A changed health status also adds a Story entry, because
 * plants.health_status is "the status of the newest entry that has one" (data-dictionary.md). Returns false when the
 * plant is not the member's (nothing changes then).
 */
function update_plant(int $user_id, int $plant_id, array $plant, string $old_health): bool
{
    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            'UPDATE plants SET collection_id = :collection_id, species_id = :species_id, custom_species = :custom_species,
                               nickname = :nickname, photo = :photo, acquired_on = :acquired_on,
                               health_status = :health_status, is_public = :is_public
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'collection_id' => $plant['collection_id'], 'species_id' => $plant['species_id'],
            'custom_species' => $plant['custom_species'], 'nickname' => $plant['nickname'], 'photo' => $plant['photo'],
            'acquired_on' => $plant['acquired_on'], 'health_status' => $plant['health_status'],
            'is_public' => $plant['is_public'], 'id' => $plant_id, 'user_id' => $user_id,
        ]);
        // rowCount() is 0 both for "not yours" and for "nothing changed", so ownership is checked with a SELECT
        $owner = db()->prepare('SELECT 1 FROM plants WHERE id = :id AND user_id = :user_id');
        $owner->execute(['id' => $plant_id, 'user_id' => $user_id]);
        if (!$owner->fetchColumn()) {
            db()->rollBack();
            return false;
        }
        if ($plant['health_status'] !== $old_health) {
            $log = db()->prepare(
                "INSERT INTO health_logs (plant_id, type, status, source, note) VALUES (:plant_id, 'note', :status, 'manual', :note)"
            );
            $log->execute(['plant_id' => $plant_id, 'status' => $plant['health_status'],
                           'note' => 'Health changed to ' . health_label($plant['health_status']) . ' on the Edit page.']);
        }
        db()->commit();
        return true;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

// ── Story, gallery, reminders done, delete (CORE-19, CORE-20, CORE-23) ───────

/** Is this plant the member's own? Every write below starts with this check. */
function owns_plant(int $user_id, int $plant_id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM plants WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $plant_id, 'user_id' => $user_id]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Set plants.health_status to the status of the plant's newest Story entry that has one (the rule in
 * data-dictionary.md). Called after an entry is added or removed. A plant with no such entry keeps its status.
 */
function refresh_plant_health(int $plant_id): void
{
    $stmt = db()->prepare(
        'UPDATE plants SET health_status = COALESCE(
             (SELECT h.status FROM health_logs h WHERE h.plant_id = :a AND h.status IS NOT NULL ORDER BY h.logged_at DESC, h.id DESC LIMIT 1),
             health_status)
         WHERE id = :b'
    );
    $stmt->execute(['a' => $plant_id, 'b' => $plant_id]);
}

/**
 * Add an entry to one of the member's plants' Story. $entry: type, care_task, status, note, photo, logged_at, and for a
 * saved AI result source 'ml' and prediction_id.
 * Returns the new id, or null when the plant is not theirs.
 */
function add_story_entry(int $user_id, int $plant_id, array $entry): ?int
{
    if (!owns_plant($user_id, $plant_id)) {
        return null;
    }
    db()->beginTransaction();
    try {
        $stmt = db()->prepare(
            'INSERT INTO health_logs (plant_id, prediction_id, type, care_task, status, source, note, photo, logged_at)
             VALUES (:plant_id, :prediction_id, :type, :care_task, :status, :source, :note, :photo, :logged_at)'
        );
        $stmt->execute([
            'plant_id' => $plant_id, 'prediction_id' => $entry['prediction_id'] ?? null, 'type' => $entry['type'],
            'care_task' => $entry['care_task'], 'status' => $entry['status'], 'source' => $entry['source'] ?? 'manual',
            'note' => $entry['note'], 'photo' => $entry['photo'], 'logged_at' => $entry['logged_at'],
        ]);
        $id = (int) db()->lastInsertId();
        if ($entry['status'] !== null) {
            refresh_plant_health($plant_id);
        }
        db()->commit();
        return $id;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

/** Remove one Story entry of the member's plant (Undo of Mark done). Returns false when it is not theirs. */
function delete_story_entry(int $user_id, int $log_id): bool
{
    $stmt = db()->prepare(
        'DELETE h FROM health_logs h JOIN plants p ON p.id = h.plant_id WHERE h.id = :id AND p.user_id = :user_id'
    );
    $stmt->execute(['id' => $log_id, 'user_id' => $user_id]);
    return $stmt->rowCount() === 1;
}

/** Add a photo to one of the member's plants' gallery. Returns false when the plant is not theirs. */
function add_plant_photo(int $user_id, int $plant_id, string $path, string $created_at): bool
{
    if (!owns_plant($user_id, $plant_id)) {
        return false;
    }
    $stmt = db()->prepare('INSERT INTO plant_photos (plant_id, path, created_at) VALUES (:plant_id, :path, :created_at)');
    $stmt->execute(['plant_id' => $plant_id, 'path' => $path, 'created_at' => $created_at]);
    return true;
}

/**
 * A reminder of the member's plant was done on $done_day: move it on to $next_due, or delete it when $next_due is
 * null (a one-off reminder). The JOIN on plants is the ownership check. Returns false when it is not theirs.
 */
function reminder_done(int $user_id, int $reminder_id, string $done_day, ?string $next_due): bool
{
    // Ownership first, with a SELECT: rowCount() after an UPDATE counts only rows that changed, so marking the same
    // reminder done twice in a day would otherwise look like "not yours"
    $owner = db()->prepare('SELECT 1 FROM care_reminders r JOIN plants p ON p.id = r.plant_id WHERE r.id = :id AND p.user_id = :user_id');
    $owner->execute(['id' => $reminder_id, 'user_id' => $user_id]);
    if (!$owner->fetchColumn()) {
        return false;
    }
    if ($next_due === null) {
        $stmt = db()->prepare('DELETE r FROM care_reminders r JOIN plants p ON p.id = r.plant_id WHERE r.id = :id AND p.user_id = :user_id');
        $stmt->execute(['id' => $reminder_id, 'user_id' => $user_id]);
    } else {
        $stmt = db()->prepare(
            'UPDATE care_reminders r JOIN plants p ON p.id = r.plant_id
             SET r.last_done = :done, r.next_due = :next
             WHERE r.id = :id AND p.user_id = :user_id'
        );
        $stmt->execute(['done' => $done_day, 'next' => $next_due, 'id' => $reminder_id, 'user_id' => $user_id]);
    }
    return true;
}

// ── Reminders: add, Mark done, Undo (CORE-22, CORE-23) ───────────────────────

/** The member's reminder for one plant and task, or null (the JOIN on plants is the ownership check). */
function find_reminder(int $user_id, int $plant_id, string $task): ?array
{
    $stmt = db()->prepare(
        'SELECT r.id, r.plant_id, r.task, r.remind_time, r.repeat_type, r.repeat_days, r.interval_days, r.last_done, r.next_due
         FROM care_reminders r JOIN plants p ON p.id = r.plant_id
         WHERE r.plant_id = :plant_id AND r.task = :task AND p.user_id = :user_id'
    );
    $stmt->execute(['plant_id' => $plant_id, 'task' => $task, 'user_id' => $user_id]);
    $row = $stmt->fetch();
    return $row ? reminder_row($row) : null;
}

/**
 * Save a reminder for one of the member's plants. A plant has one reminder per task (UNIQUE (plant_id, task)), so a
 * new one for the same task replaces the old: INSERT … ON DUPLICATE KEY UPDATE. $reminder: plant_id, task,
 * remind_time, repeat_type, repeat_days (list), interval_days, last_done, next_due. Returns false when the plant is
 * not theirs.
 */
function save_reminder(int $user_id, array $reminder): bool
{
    if (!owns_plant($user_id, (int) $reminder['plant_id'])) {
        return false;
    }
    $stmt = db()->prepare(
        'INSERT INTO care_reminders (plant_id, task, remind_time, repeat_type, repeat_days, interval_days, last_done, next_due)
         VALUES (:plant_id, :task, :remind_time, :repeat_type, :repeat_days, :interval_days, :last_done, :next_due)
         ON DUPLICATE KEY UPDATE remind_time = VALUES(remind_time), repeat_type = VALUES(repeat_type),
             repeat_days = VALUES(repeat_days), interval_days = VALUES(interval_days),
             last_done = VALUES(last_done), next_due = VALUES(next_due)'
    );
    $stmt->execute([
        'plant_id'      => $reminder['plant_id'],
        'task'          => $reminder['task'],
        'remind_time'   => $reminder['remind_time'],
        'repeat_type'   => $reminder['repeat_type'],
        'repeat_days'   => $reminder['repeat_days'] ? implode(',', $reminder['repeat_days']) : null,
        'interval_days' => $reminder['interval_days'],
        'last_done'     => $reminder['last_done'],
        'next_due'      => $reminder['next_due'],
    ]);
    return true;
}

/** Remove the member's reminder for one plant and task (Undo of a newly added reminder). */
function remove_reminder(int $user_id, int $plant_id, string $task): void
{
    $stmt = db()->prepare(
        'DELETE r FROM care_reminders r JOIN plants p ON p.id = r.plant_id
         WHERE r.plant_id = :plant_id AND r.task = :task AND p.user_id = :user_id'
    );
    $stmt->execute(['plant_id' => $plant_id, 'task' => $task, 'user_id' => $user_id]);
}

/**
 * Mark done (CORE-23), in one transaction: the reminder moves on to $next_due (or is deleted when $next_due is null,
 * a one-off), and unless it is a re-check the plant's Story gets a care line ("Watered · 12 Nov").
 * Returns the new Story entry's id (for Undo), 0 when no line was added, or null when the reminder is not theirs.
 */
function mark_reminder_done(int $user_id, array $reminder, string $done_day, ?string $next_due): ?int
{
    db()->beginTransaction();
    try {
        if (!reminder_done($user_id, (int) $reminder['id'], $done_day, $next_due)) {
            db()->rollBack();
            return null;
        }
        $log_id = 0;
        if ($reminder['task'] !== 'recheck') {   // a re-check is not care given to the plant
            $stmt = db()->prepare(
                "INSERT INTO health_logs (plant_id, type, care_task, source) VALUES (:plant_id, 'care', :task, 'manual')"
            );
            $stmt->execute(['plant_id' => $reminder['plant_id'], 'task' => $reminder['task']]);
            $log_id = (int) db()->lastInsertId();
        }
        db()->commit();
        return $log_id;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

/**
 * Delete one of the member's plants (CORE-20). The foreign keys remove its reminders, Story, gallery rows and likes
 * (CASCADE) and keep any listing or AI result that pointed at it (SET NULL). Its photo files are then deleted, each
 * only if nothing else uses it. Returns the deleted plant's nickname, or null when it was not theirs.
 */
function delete_plant(int $user_id, int $plant_id): ?string
{
    $stmt = db()->prepare('SELECT nickname, photo FROM plants WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $plant_id, 'user_id' => $user_id]);
    $plant = $stmt->fetch();
    if (!$plant) {
        return null;
    }
    // Every file the plant used, collected before its rows disappear
    $stmt = db()->prepare(
        'SELECT path FROM plant_photos WHERE plant_id = :a UNION SELECT photo FROM health_logs WHERE plant_id = :b AND photo IS NOT NULL'
    );
    $stmt->execute(['a' => $plant_id, 'b' => $plant_id]);
    $files = array_unique(array_merge([$plant['photo']], $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $stmt = db()->prepare('DELETE FROM plants WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $plant_id, 'user_id' => $user_id]);
    foreach ($files as $file) {
        forget_photo($file);
    }
    return $plant['nickname'];
}

/**
 * Delete an uploaded photo file once nothing in the database uses it any more. Several plants added together share
 * one photo, and a plant's photo is also in its first Story entry, so a file can have more than one user.
 */
function forget_photo(?string $path): void
{
    if ($path === null || str_starts_with($path, 'mock/')) {
        return;
    }
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM plants WHERE photo = :a) + (SELECT COUNT(*) FROM plant_photos WHERE path = :b)
              + (SELECT COUNT(*) FROM health_logs WHERE photo = :c) + (SELECT COUNT(*) FROM exchange_listings WHERE photo = :d)
              + (SELECT COUNT(*) FROM listing_photos WHERE path = :e) + (SELECT COUNT(*) FROM users WHERE avatar = :f)
              + (SELECT COUNT(*) FROM ml_predictions WHERE image_path = :g)'
    );
    $stmt->execute(['a' => $path, 'b' => $path, 'c' => $path, 'd' => $path, 'e' => $path, 'f' => $path, 'g' => $path]);
    if ((int) $stmt->fetchColumn() === 0) {
        require_once __DIR__ . '/upload.php';
        delete_upload($path);
    }
}

/**
 * Every photo file a member's rows point at: avatar, plant photos, gallery, Story, listings and AI scans. Read before
 * the account is deleted (Settings), because the rows vanish with it (CASCADE) and only they know the file names.
 * Afterwards each path goes through forget_photo(), which deletes a file only when nothing else still uses it.
 */
function member_photo_files(int $user_id): array
{
    $stmt = db()->prepare(
        'SELECT avatar FROM users WHERE id = :a
         UNION SELECT photo FROM plants WHERE user_id = :b
         UNION SELECT ph.path FROM plant_photos ph JOIN plants p ON p.id = ph.plant_id WHERE p.user_id = :c
         UNION SELECT h.photo FROM health_logs h JOIN plants p ON p.id = h.plant_id WHERE p.user_id = :d
         UNION SELECT photo FROM exchange_listings WHERE user_id = :e
         UNION SELECT lp.path FROM listing_photos lp JOIN exchange_listings l ON l.id = lp.listing_id WHERE l.user_id = :f
         UNION SELECT image_path FROM ml_predictions WHERE user_id = :g'
    );
    $stmt->execute(array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g'], $user_id));
    return array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), fn ($path) => $path !== null && $path !== ''));
}

/** How many plants and collections the member has, private ones included: their own pages show these totals. */
function garden_counts(int $user_id): array
{
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM plants WHERE user_id = :user_id) AS plant_count,
                (SELECT COUNT(*) FROM collections WHERE user_id = :user_id2) AS collection_count'
    );
    $stmt->execute(['user_id' => $user_id, 'user_id2' => $user_id]);
    return $stmt->fetch();
}
