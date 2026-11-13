<?php
// The exchange read from and saved to the database: listings, interests, finished exchanges, ratings, towns
// (COM-05 … COM-13). Each read function returns rows shaped like the stage F mock arrays, so the pages did not change;
// mock-data.php fills the old array names from them. Each write function carries its own ownership and state rules
// in the SQL itself (owner, open or closed listing, once per member), so a hand-made request can never get round them.

require_once __DIR__ . '/db.php';

// ── Reading ──────────────────────────────────────────────────────────────────

/** Every town, keyed by name, in the shape of $mock_towns (coordinates as numbers, for the distance formula). */
function towns_by_name(): array
{
    $towns = [];
    foreach (db()->query('SELECT id, name, latitude, longitude FROM towns ORDER BY name')->fetchAll() as $town) {
        $town['latitude']  = (float) $town['latitude'];   // DECIMAL arrives as text
        $town['longitude'] = (float) $town['longitude'];
        $towns[$town['name']] = $town;
    }
    return $towns;
}

/**
 * Every listing, newest first, in the shape of $mock_listings: every column, the town as location, the species
 * (the offered plant's, else the listing's own), the owner's public columns and rating, the live interest count and
 * the categories as a list. distance_km is added by mock-data.php, because it depends on who is looking.
 */
function all_listings(): array
{
    $rows = db()->query(
        "SELECT l.id, l.user_id, l.plant_id, l.title, l.description, l.type, l.looking_for, l.town_id, t.name AS location,
                l.km_from_town, l.size, l.plant_condition, l.light_level, l.water_need, l.photo, l.share_email,
                l.share_phone, l.status, l.exchanged_with, l.exchanged_at, l.created_at,
                COALESCE(p.species_id, l.species_id) AS species_id,
                COALESCE(s.common_name, p.custom_species) AS common_name, s.scientific_name,
                u.username, u.full_name, u.avatar,
                (SELECT COUNT(*) FROM listing_interests i WHERE i.listing_id = l.id AND i.status <> 'withdrawn') AS interest_count,
                (SELECT ROUND(AVG(r.score), 1) FROM user_ratings r WHERE r.rated_id = l.user_id) AS rating_avg,
                (SELECT COUNT(*) FROM user_ratings r WHERE r.rated_id = l.user_id) AS rating_count,
                (SELECT GROUP_CONCAT(c.category ORDER BY c.category) FROM listing_categories c WHERE c.listing_id = l.id) AS categories
         FROM exchange_listings l
         JOIN users u ON u.id = l.user_id
         JOIN towns t ON t.id = l.town_id
         LEFT JOIN plants p ON p.id = l.plant_id
         LEFT JOIN plant_species s ON s.id = COALESCE(p.species_id, l.species_id)
         ORDER BY l.created_at DESC, l.id DESC"
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['categories'] = $row['categories'] === null ? [] : explode(',', $row['categories']);
        $row['rating_avg'] = (float) $row['rating_avg'];
    }
    return $rows;
}

/** Every extra listing photo, oldest first, in the shape of $mock_listing_photos. */
function all_listing_photos(): array
{
    return db()->query('SELECT id, listing_id, path, created_at FROM listing_photos ORDER BY created_at, id')->fetchAll();
}

/** The interests a member has sent (every status), in the shape of $mock_listing_interests. */
function sent_interests(int $user_id): array
{
    $stmt = db()->prepare(
        'SELECT id, listing_id, user_id, message, status, created_at FROM listing_interests WHERE user_id = :user_id ORDER BY created_at DESC'
    );
    $stmt->execute(['user_id' => $user_id]);
    return $stmt->fetchAll();
}

/**
 * Interest in a member's own listings, newest first, in the shape of $mock_received_interests: the interest, the
 * requester's public columns and rating, and the listing's title.
 */
function received_interests(int $owner_id): array
{
    $stmt = db()->prepare(
        'SELECT i.id, i.listing_id, i.user_id, i.message, i.status, i.created_at,
                u.username, u.full_name, u.avatar,
                (SELECT ROUND(AVG(r.score), 1) FROM user_ratings r WHERE r.rated_id = i.user_id) AS rating_avg,
                (SELECT COUNT(*) FROM user_ratings r WHERE r.rated_id = i.user_id) AS rating_count,
                l.title
         FROM listing_interests i
         JOIN exchange_listings l ON l.id = i.listing_id
         JOIN users u ON u.id = i.user_id
         WHERE l.user_id = :owner_id
         ORDER BY i.created_at DESC, i.id DESC'
    );
    $stmt->execute(['owner_id' => $owner_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['rating_avg'] = (float) $row['rating_avg'];
    }
    return $rows;
}

/**
 * Ratings with their writer and the listing, newest first, in the shape of $mock_user_ratings. $only_rater: just the
 * ratings that member gave (with or without a comment); null: every rating that has a written comment (reviews).
 * A writer who deleted their account has rater_id NULL: shown as "Former member".
 */
function ratings_with_names(?int $only_rater = null): array
{
    $sql = 'SELECT r.id, r.rater_id, r.rated_id, r.listing_id, r.score, r.comment, r.created_at,
                   u.username AS rater_username, COALESCE(u.full_name, :former) AS rater_full_name, u.avatar AS rater_avatar,
                   l.title AS listing_title
            FROM user_ratings r
            LEFT JOIN users u ON u.id = r.rater_id
            LEFT JOIN exchange_listings l ON l.id = r.listing_id
            WHERE ' . ($only_rater === null ? 'r.comment IS NOT NULL' : 'r.rater_id = :rater') . '
            ORDER BY r.created_at DESC, r.id DESC';
    $stmt = db()->prepare($sql);
    $params = ['former' => 'Former member'];
    if ($only_rater !== null) {
        $params['rater'] = $only_rater;
    }
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** How many ratings of each score every member received, in the shape of $mock_rating_counts. */
function rating_counts(): array
{
    return db()->query('SELECT rated_id, score, COUNT(*) AS rating_count FROM user_ratings GROUP BY rated_id, score ORDER BY rated_id, score DESC')
               ->fetchAll();
}

/**
 * Other members' PUBLIC collections, in the shape of $mock_community_collections: plant_count and cover_photos count
 * and show public plants only, because other people see them. $viewer_id's own are left out (they are in
 * garden_collections()); null for a visitor.
 */
function others_public_collections(?int $viewer_id): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.user_id, c.name, c.is_public, c.created_at,
                COUNT(p.id) AS plant_count,
                GROUP_CONCAT(p.photo ORDER BY (SELECT COUNT(*) FROM plant_likes k WHERE k.plant_id = p.id) DESC, p.id SEPARATOR "\n") AS photos
         FROM collections c
         LEFT JOIN plants p ON p.collection_id = c.id AND p.is_public = 1
         WHERE c.is_public = 1 AND (:viewer IS NULL OR c.user_id <> :viewer2)
         GROUP BY c.id
         ORDER BY c.user_id, c.created_at, c.id'
    );
    $stmt->execute(['viewer' => $viewer_id, 'viewer2' => $viewer_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['cover_photos'] = array_slice(array_filter(explode("\n", (string) $row['photos'])), 0, 3);
        $row['plant_count']  = (int) $row['plant_count'];
        unset($row['photos']);
    }
    return $rows;
}

/**
 * Each member's trader numbers, keyed by id: rating_avg and rating_count (ratings received) and swap_count
 * (closed swap listings they took part in, as owner or as the one it went to).
 */
function trader_numbers(): array
{
    $rows = db()->query(
        "SELECT u.id,
                COALESCE((SELECT ROUND(AVG(r.score), 1) FROM user_ratings r WHERE r.rated_id = u.id), 0) AS rating_avg,
                (SELECT COUNT(*) FROM user_ratings r WHERE r.rated_id = u.id) AS rating_count,
                (SELECT COUNT(*) FROM exchange_listings l
                 WHERE l.status = 'closed' AND l.type = 'swap' AND (l.user_id = u.id OR l.exchanged_with = u.id)) AS swap_count
         FROM users u"
    )->fetchAll();
    $numbers = [];
    foreach ($rows as $row) {
        $numbers[(int) $row['id']] = ['rating_avg' => (float) $row['rating_avg'], 'rating_count' => (int) $row['rating_count'],
                                      'swap_count' => (int) $row['swap_count']];
    }
    return $numbers;
}

/** How many people newly showed interest in the member's open listings: the dot on the header's bell. */
function new_interest_count(int $owner_id): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM listing_interests i JOIN exchange_listings l ON l.id = i.listing_id
         WHERE l.user_id = :owner_id AND l.status = 'open' AND i.status = 'new'"
    );
    $stmt->execute(['owner_id' => $owner_id]);
    return (int) $stmt->fetchColumn();
}

/**
 * The contact details a listing's owner chose to share, for one viewer, or null. Only two people ever get a row:
 * the owner (their own details) and a member with a live interest in the open listing. Fields not shared are null.
 * The contact columns are read here and nowhere else, so no page can print them by mistake (privacy rule).
 */
function listing_contact(int $listing_id, int $viewer_id): ?array
{
    $stmt = db()->prepare(
        "SELECT IF(l.share_email, u.email, NULL) AS email, IF(l.share_phone, u.phone, NULL) AS phone
         FROM exchange_listings l JOIN users u ON u.id = l.user_id
         WHERE l.id = :id AND l.status = 'open'
           AND (l.user_id = :viewer
                OR EXISTS (SELECT 1 FROM listing_interests i
                           WHERE i.listing_id = l.id AND i.user_id = :viewer2 AND i.status <> 'withdrawn'))"
    );
    $stmt->execute(['id' => $listing_id, 'viewer' => $viewer_id, 'viewer2' => $viewer_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ── Saving ───────────────────────────────────────────────────────────────────

/**
 * "I'm interested" (COM-09): one row per member and listing (UNIQUE (listing_id, user_id)), so showing interest again
 * after withdrawing turns the old row back to 'new'. Only an open listing, and never your own: both are in the SQL.
 * Returns true when the interest was saved.
 */
function send_interest(int $user_id, int $listing_id, ?string $message): bool
{
    $stmt = db()->prepare(
        "INSERT INTO listing_interests (listing_id, user_id, message, status)
         SELECT l.id, :user_id, :message, 'new' FROM exchange_listings l
         WHERE l.id = :listing_id AND l.status = 'open' AND l.user_id <> :user_id2
         ON DUPLICATE KEY UPDATE message = VALUES(message), status = 'new', created_at = NOW()"
    );
    $stmt->execute(['user_id' => $user_id, 'message' => $message, 'listing_id' => $listing_id, 'user_id2' => $user_id]);
    return $stmt->rowCount() > 0;
}

/** Withdraw interest: the row stays, marked 'withdrawn', so it no longer counts (COM-09). Only the member's own row. */
function withdraw_interest(int $user_id, int $listing_id): void
{
    $stmt = db()->prepare("UPDATE listing_interests SET status = 'withdrawn' WHERE listing_id = :listing_id AND user_id = :user_id");
    $stmt->execute(['listing_id' => $listing_id, 'user_id' => $user_id]);
}

/**
 * Mark as exchanged (COM-11): the owner closes their open listing and records who it went to, the member behind one of
 * its live interests. Returns true when the listing was closed.
 */
function mark_exchanged(int $owner_id, int $interest_id): bool
{
    $stmt = db()->prepare(
        "UPDATE exchange_listings l JOIN listing_interests i ON i.listing_id = l.id
         SET l.status = 'closed', l.exchanged_with = i.user_id, l.exchanged_at = NOW()
         WHERE i.id = :interest_id AND l.user_id = :owner_id AND l.status = 'open' AND i.status <> 'withdrawn'"
    );
    $stmt->execute(['interest_id' => $interest_id, 'owner_id' => $owner_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Rate the other member of a finished exchange (COM-12). Only the two members of a closed listing may rate, each once
 * (UNIQUE (rater_id, listing_id)); the one rated is always the other side, worked out by the SQL, never sent by the
 * form. Returns true when the rating was saved, false when it was not allowed or already given.
 */
function rate_exchange(int $rater_id, int $listing_id, int $score, ?string $comment): bool
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO user_ratings (rater_id, rated_id, listing_id, score, comment)
             SELECT :rater, IF(l.user_id = :rater2, l.exchanged_with, l.user_id), l.id, :score, :comment
             FROM exchange_listings l
             WHERE l.id = :listing_id AND l.status = 'closed' AND l.exchanged_with IS NOT NULL
               AND (l.user_id = :rater3 OR l.exchanged_with = :rater4)"
        );
        $stmt->execute(['rater' => $rater_id, 'rater2' => $rater_id, 'score' => $score, 'comment' => $comment,
                        'listing_id' => $listing_id, 'rater3' => $rater_id, 'rater4' => $rater_id]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            return false;   // rated this exchange already
        }
        throw $error;
    }
}

/**
 * Save a listing (COM-06) and its categories in one transaction. $listing_id null: a new listing; otherwise the
 * member's own open listing is updated (closed or someone else's: nothing changes, false is returned).
 * $fields: plant_id, species_id, title, description, type, looking_for, town_id, km_from_town, size, plant_condition,
 * light_level, water_need, photo, share_email, share_phone. Returns the listing id, or false.
 */
function save_listing(int $user_id, ?int $listing_id, array $fields, array $categories)
{
    $columns = ['plant_id', 'species_id', 'title', 'description', 'type', 'looking_for', 'town_id', 'km_from_town', 'size',
                'plant_condition', 'light_level', 'water_need', 'photo', 'share_email', 'share_phone'];
    $params = [];
    foreach ($columns as $column) {
        $params[$column] = $fields[$column];
    }
    db()->beginTransaction();
    try {
        if ($listing_id === null) {
            $stmt = db()->prepare(
                'INSERT INTO exchange_listings (user_id, ' . implode(', ', $columns) . ')
                 VALUES (:user_id, :' . implode(', :', $columns) . ')'
            );
            $stmt->execute($params + ['user_id' => $user_id]);
            $listing_id = (int) db()->lastInsertId();
        } else {
            $owner = db()->prepare("SELECT 1 FROM exchange_listings WHERE id = :id AND user_id = :user_id AND status = 'open'");
            $owner->execute(['id' => $listing_id, 'user_id' => $user_id]);
            if (!$owner->fetchColumn()) {
                db()->rollBack();
                return false;
            }
            $set  = implode(', ', array_map(fn ($column) => $column . ' = :' . $column, $columns));
            $stmt = db()->prepare('UPDATE exchange_listings SET ' . $set . ' WHERE id = :id AND user_id = :user_id');
            $stmt->execute($params + ['id' => $listing_id, 'user_id' => $user_id]);
        }
        // The categories are replaced as a whole: delete the old pairs, insert the chosen ones
        db()->prepare('DELETE FROM listing_categories WHERE listing_id = :id')->execute(['id' => $listing_id]);
        $insert = db()->prepare('INSERT INTO listing_categories (listing_id, category) VALUES (:id, :category)');
        foreach (array_unique($categories) as $category) {
            $insert->execute(['id' => $listing_id, 'category' => $category]);
        }
        db()->commit();
        return $listing_id;
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
}

/** Close one of the member's open listings without an exchange (it stays, closed, for the history). */
function close_listing(int $user_id, int $listing_id): bool
{
    $stmt = db()->prepare("UPDATE exchange_listings SET status = 'closed' WHERE id = :id AND user_id = :user_id AND status = 'open'");
    $stmt->execute(['id' => $listing_id, 'user_id' => $user_id]);
    return $stmt->rowCount() === 1;
}

/** The owner has now seen the new interest in one of their listings: those rows turn from 'new' to 'seen'. */
function mark_interests_seen(int $owner_id, int $listing_id): void
{
    $stmt = db()->prepare(
        "UPDATE listing_interests i JOIN exchange_listings l ON l.id = i.listing_id
         SET i.status = 'seen'
         WHERE i.listing_id = :listing_id AND l.user_id = :owner_id AND i.status = 'new'"
    );
    $stmt->execute(['listing_id' => $listing_id, 'owner_id' => $owner_id]);
}
