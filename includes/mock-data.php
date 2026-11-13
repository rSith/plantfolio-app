<?php
// Stage F only: arrays shaped like future database rows (MOD-01 foundation, week 1).
// Each array is replaced by a PDO query in stage B, then removed from this file.
// Sample content comes from the wireframes so pages can be compared with the PDF and Figma.

require_once __DIR__ . '/auth.php';   // start_session(), current_user(): stage B, used by the bridge below

/**
 * Mock-only helper: the date N days from today (-2 is two days ago, 0 is today).
 * Reminder and listing dates are counted from today, so "Water today" and "Overdue 2 days"
 * stay true on whatever day the page is opened — including the day of the demo.
 */
function mock_date($days)
{
    return date('Y-m-d', strtotime($days . ' days'));
}

// The signed-in member — one row of the `users` table (wireframes p21, p22).
// password_hash, failed_logins and locked_until are left out: pages never display them.
$current_user = [
    'id'         => 1,
    'username'   => 'nimali.grows',
    'full_name'  => 'Nimali Perera',
    'email'      => 'nimali.p@example.com',
    'phone'      => '+94 77 000 1234',       // p18 and p19: shared only with people interested in a listing
    'bio'        => 'Balcony gardener growing aroids and far too many pothos. Always happy to swap cuttings.',
    'location'   => 'Kurunegala',
    'km_from_town' => 3,   // users.km_from_town: Nimali lives about 3 km from Kurunegala's centre (invented)
    'avatar'     => null,                    // no photo: pages show the initials "NP" instead
    'created_at' => '2025-03-14 09:00:00',   // "Member since Mar 2025"

    // Preferences from Settings (wireframe p22, cards 4 and 5): users columns accepted in DES-07, each a TINYINT 0/1.
    // No listing-area setting: a listing always shows the town only (DES-07 removed show_listing_area).
    'new_plants_public'   => 1,   // Privacy: new plants start Public on the Add Plant form
    'dashboard_reminders' => 1,   // Reminders: care tasks appear on the Dashboard

    // Not columns of `users`: stage B calculates these with COUNT() and AVG() on other tables.
    // Her own pages count everything, private plants and collections included. Other people see smaller,
    // public-only numbers: those are in $mock_users below (13 plants, 2 collections).
    // 20 = the 18 in her three collections (p11) + Penny and Heidi, who are in no collection yet.
    'plant_count'      => 20,
    'collection_count' => 3,
    'swap_count'       => 12,
    'rating_avg'       => 4.9,
    'rating_count'     => 23,
    'new_interest_count' => 4,   // people newly interested in her listings (p10): the dot on the header bell
];

// Who is logged in: the real session (includes/auth.php, CORE-09). A visitor has no users row, so $current_user is null.
// The member's counts and trader numbers are added from the database further down (steps 3.1 and 4.1); these zeros
// only make sure every key exists until then. (The stage F ?member and ?guest shortcuts were removed with the real
// login: they would let anyone see Nimali's pages without her password. Log in as nimali.grows, see database/README.md.)
start_session();
$session_user = current_user();
$current_user = $session_user === null ? null : $session_user + [
    'plant_count' => 0, 'collection_count' => 0, 'swap_count' => 0,
    'rating_avg' => 0, 'rating_count' => 0, 'new_interest_count' => 0,
];

// Nimali's plants — rows of `plants` (wireframe p11), each joined with:
//   plant_species  → scientific_name, common_name
//   collections    → collection_name
//   care_reminders → task, interval_days, next_due of the plant's soonest reminder
//   health_logs    → last_logged_at, the date of the plant's newest log (My Garden's "Recently updated" sort)
// The order is the order of wireframe p11.
$mock_plants = [
    ['id' => 42, 'user_id' => 1, 'collection_id' => 1, 'species_id' => 1, 'nickname' => 'Monty',
     'photo' => 'mock/monty.svg', 'acquired_on' => '2025-03-20', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Monstera deliciosa', 'common_name' => 'Swiss cheese plant',
     'collection_name' => 'Living room',
     'task' => 'water', 'interval_days' => 7, 'next_due' => mock_date(0),
     'last_logged_at' => mock_date(-5) . ' 18:20:00'],

    ['id' => 43, 'user_id' => 1, 'collection_id' => 1, 'species_id' => 2, 'nickname' => 'Goldie',
     'photo' => 'mock/goldie.svg', 'acquired_on' => '2025-04-02', 'health_status' => 'needs_attention', 'is_public' => 1,
     'scientific_name' => 'Epipremnum aureum', 'common_name' => 'Golden pothos',
     'collection_name' => 'Living room',
     'task' => 'water', 'interval_days' => 5, 'next_due' => mock_date(-2),
     'last_logged_at' => mock_date(-3) . ' 18:10:00'],

    ['id' => 44, 'user_id' => 1, 'collection_id' => 1, 'species_id' => 3, 'nickname' => 'Sergeant',
     'photo' => 'mock/sergeant.svg', 'acquired_on' => '2025-05-11', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Dracaena trifasciata', 'common_name' => 'Snake plant',
     'collection_name' => 'Living room',
     'task' => 'fertilise', 'interval_days' => 30, 'next_due' => mock_date(0),
     'last_logged_at' => mock_date(-12) . ' 09:00:00'],

    ['id' => 45, 'user_id' => 1, 'collection_id' => 1, 'species_id' => 4, 'nickname' => 'Figgy',
     'photo' => 'mock/figgy.svg', 'acquired_on' => '2025-06-18', 'health_status' => 'stable', 'is_public' => 1,
     'scientific_name' => 'Ficus lyrata', 'common_name' => 'Fiddle-leaf fig',
     'collection_name' => 'Living room',
     'task' => 'water', 'interval_days' => 7, 'next_due' => mock_date(3),
     'last_logged_at' => mock_date(-9) . ' 17:30:00'],

    // Two more snake plants in Living room, so Sergeant and these two show as one stack in My Garden (prototype on
    // feature/plant-stacks). They are two of Living room's 7 that had no sample row yet, so no count changes.
    // Public like the rest of Living room (13 public plants = Living room 7 + Balcony 6). Stripes is sick on purpose:
    // the stack card must show that one of three is in trouble.
    ['id' => 64, 'user_id' => 1, 'collection_id' => 1, 'species_id' => 3, 'nickname' => 'Stripes',
     'photo' => 'mock/sergeant.svg', 'acquired_on' => '2025-05-11', 'health_status' => 'sick', 'is_public' => 1,
     'scientific_name' => 'Dracaena trifasciata', 'common_name' => 'Snake plant',
     'collection_name' => 'Living room',
     'task' => 'water', 'interval_days' => 14, 'next_due' => mock_date(-1),
     'last_logged_at' => mock_date(-2) . ' 17:05:00'],

    ['id' => 65, 'user_id' => 1, 'collection_id' => 1, 'species_id' => 3, 'nickname' => 'Slim',
     'photo' => 'mock/sergeant.svg', 'acquired_on' => '2025-05-11', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Dracaena trifasciata', 'common_name' => 'Snake plant',
     'collection_name' => 'Living room',
     'task' => 'water', 'interval_days' => 14, 'next_due' => mock_date(5),
     'last_logged_at' => mock_date(-12) . ' 09:00:00'],

    ['id' => 48, 'user_id' => 1, 'collection_id' => 1, 'species_id' => 7, 'nickname' => 'Lily',
     'photo' => 'mock/lily.svg', 'acquired_on' => '2025-09-02', 'health_status' => 'stable', 'is_public' => 1,
     'scientific_name' => 'Spathiphyllum wallisii', 'common_name' => 'Peace lily',
     'collection_name' => 'Living room',
     'task' => 'water', 'interval_days' => 4, 'next_due' => mock_date(1),
     'last_logged_at' => mock_date(-15) . ' 08:40:00'],

    ['id' => 46, 'user_id' => 1, 'collection_id' => 3, 'species_id' => 5, 'nickname' => 'Vera',
     'photo' => 'mock/vera.svg', 'acquired_on' => '2025-07-06', 'health_status' => 'thriving', 'is_public' => 0,
     'scientific_name' => 'Aloe vera', 'common_name' => 'Aloe vera',
     'collection_name' => 'Succulents',
     'task' => 'water', 'interval_days' => 14, 'next_due' => mock_date(9),
     'last_logged_at' => mock_date(-20) . ' 16:00:00'],

    ['id' => 47, 'user_id' => 1, 'collection_id' => 2, 'species_id' => 6, 'nickname' => 'Tommy',
     'photo' => 'mock/tommy.svg', 'acquired_on' => '2025-08-23', 'health_status' => 'sick', 'is_public' => 1,
     'scientific_name' => 'Solanum lycopersicum', 'common_name' => 'Tomato',
     'collection_name' => 'Balcony',
     'task' => 'water', 'interval_days' => 2, 'next_due' => mock_date(1),
     'last_logged_at' => mock_date(-1) . ' 07:46:00'],   // the Health Check on the Dashboard

    // Pearl is in the private Succulents collection, so she is private too, like Vera
    ['id' => 49, 'user_id' => 1, 'collection_id' => 3, 'species_id' => 8, 'nickname' => 'Pearl',
     'photo' => 'mock/pearl.svg', 'acquired_on' => '2025-09-27', 'health_status' => 'thriving', 'is_public' => 0,
     'scientific_name' => 'Echeveria elegans', 'common_name' => 'Mexican snowball',
     'collection_name' => 'Succulents',
     'task' => 'water', 'interval_days' => 10, 'next_due' => mock_date(6),
     'last_logged_at' => mock_date(-26) . ' 10:15:00'],

    // Two new plants not sorted into a collection yet (collection_id NULL, "No collection" on Add plant), so the
    // New collection popup has plants to pick (CORE-13). Private, so the public counts (13 plants) stay the same.
    // No reminder yet: task, interval_days and next_due are NULL, as the LEFT JOIN on care_reminders gives.
    ['id' => 62, 'user_id' => 1, 'collection_id' => null, 'species_id' => 11, 'nickname' => 'Penny',
     'photo' => 'mock/pip.svg', 'acquired_on' => mock_date(-6), 'health_status' => 'stable', 'is_public' => 0,
     'scientific_name' => 'Pilea peperomioides', 'common_name' => 'Chinese money plant',
     'collection_name' => null,
     'task' => null, 'interval_days' => null, 'next_due' => null,
     'last_logged_at' => mock_date(-6) . ' 11:00:00'],

    ['id' => 63, 'user_id' => 1, 'collection_id' => null, 'species_id' => 12, 'nickname' => 'Heidi',
     'photo' => 'mock/goldie.svg', 'acquired_on' => mock_date(-4), 'health_status' => 'thriving', 'is_public' => 0,
     'scientific_name' => 'Philodendron hederaceum', 'common_name' => 'Heartleaf philodendron',
     'collection_name' => null,
     'task' => null, 'interval_days' => null, 'next_due' => null,
     'last_logged_at' => mock_date(-4) . ' 15:30:00'],
];

// Nimali's collections — rows of `collections` (wireframe p11, element 2), plus two calculated columns:
//   plant_count  — a COUNT() on plants
//   cover_photos — the photos of up to three of its plants, most liked first: the collage on her Public Profile
//                  (p21, element 5). Stage B: GROUP_CONCAT(plants.photo ORDER BY like_count DESC), split with explode().
// My Garden counts every plant. The Public Profile's query adds AND plants.is_public = 1 to both calculated columns,
// because a public collection may hold a private plant (Add plant allows it). Here her 13 public plants are exactly
// the 7 in Living room and the 6 in Balcony, so one number serves both pages.
// A collection with is_public = 0 is private: the page shows a lock and hides it from others.
// The counts are her whole garden (18 plants, as on p11 and the Dashboard); $mock_plants holds only a sample of 8.
// So the Balcony collage borrows two placeholder drawings (aloe, snake plant) for Balcony plants that are not in the sample.
$mock_collections = [
    ['id' => 1, 'user_id' => 1, 'name' => 'Living room', 'is_public' => 1, 'plant_count' => 7,
     'cover_photos' => ['mock/monty.svg', 'mock/sergeant.svg', 'mock/lily.svg']],
    ['id' => 2, 'user_id' => 1, 'name' => 'Balcony',     'is_public' => 1, 'plant_count' => 6,
     'cover_photos' => ['mock/tommy.svg', 'mock/vera.svg', 'mock/sergeant.svg']],
    ['id' => 3, 'user_id' => 1, 'name' => 'Succulents',  'is_public' => 0, 'plant_count' => 5,
     'cover_photos' => ['mock/pearl.svg', 'mock/vera.svg', 'mock/pearl.svg']],
];

// Care that is due today or overdue — rows of `care_reminders` (wireframe p10), each joined with:
//   plants → nickname, photo        plant_species → common_name
// Every row keeps the rule from database/README.md: next_due = last_done + interval_days.
$mock_reminders = [
    ['id' => 12, 'plant_id' => 43, 'task' => 'water', 'interval_days' => 5,
     'last_done' => mock_date(-7), 'next_due' => mock_date(-2),
     'nickname' => 'Goldie', 'photo' => 'mock/goldie.svg', 'common_name' => 'Golden pothos'],

    ['id' => 11, 'plant_id' => 42, 'task' => 'water', 'interval_days' => 7,
     'last_done' => mock_date(-7), 'next_due' => mock_date(0),
     'nickname' => 'Monty', 'photo' => 'mock/monty.svg', 'common_name' => 'Swiss cheese plant'],

    ['id' => 13, 'plant_id' => 44, 'task' => 'fertilise', 'interval_days' => 30,
     'last_done' => mock_date(-30), 'next_due' => mock_date(0),
     'nickname' => 'Sergeant', 'photo' => 'mock/sergeant.svg', 'common_name' => 'Snake plant'],
];

// Listings on the marketplace, newest first — rows of `exchange_listings` (wireframes p17, p8), each joined with:
//   users         → username, full_name, avatar of the owner
//   plant_species → species_id, common_name, scientific_name, for the Species filter and the search (P10).
//                   A listing that offers one of the owner's plants takes the species of that plant (plant_id → plants);
//                   seedlings and cuttings listed without a plant (plant_id null) need their own species_id: a proposed
//                   exchange_listings column for DES-07. Stage B: COALESCE(plants.species_id, exchange_listings.species_id)
//                   AS species_id, then LEFT JOIN plant_species on it.
// and four calculated columns: rating_avg and rating_count (from user_ratings), interest_count
// (from listing_interests) and distance_km (between the viewer's town and the listing's town; null for a visitor,
// who has no town: see below the array).
// location is the town where the plant can be picked up, typed on the listing form. It can differ from the owner's home
// town (users.location): Dinesh lives in Negombo but meets people in Kuliyapitiya and Pannala, on the way to Kurunegala.
// Rows with status 'closed' are finished exchanges (exchanged_with is the other member): pages show open rows only.
// exchanged_at is the day the owner pressed "Mark as exchanged" (p20: "Exchanged 12 Sep"): a proposed column for DES-07,
// null while the listing is open.
// Ids are not in date order (the Newest sort uses created_at). Rows 6 to 10 are from p17, with p17's towns, distances,
// ages and interest counts. Echeveria (11) is p17's last card; its owner, the Monstera row (12, the 'either' example),
// the philodendron row (13, the one far away) and every looking_for that p17 cuts off are invented.
// The last two lines of each row hold the columns only the Listing Detail page (P11) prints:
//   description, share_email, share_phone — from the table plan. share_email and share_phone (TINYINT 0/1)
//                    are the owner's choices on the listing form (P12): which contact details an interested member may see.
//                    There is no area choice: a listing names the town, never an address (DES-07 removed approx_area).
//   size, plant_condition — proposed exchange_listings columns for DES-07 (short optional text, p18's details list).
//                    Not "condition": that word is reserved in MySQL.
// Listing 7's are from p18; the others are invented.
// interest_count counts the interests that are not withdrawn. Nimali's own interests in listings 11 and 13 are one of
// their 2 and their 1;
// her preview changes (mock_send_interest() below) are added to or taken from these numbers at the end of this file.
$mock_listings = [
    ['id' => 7, 'user_id' => 1, 'plant_id' => 43, 'title' => "Pothos 'Golden', 3 rooted cuttings",
     'type' => 'swap', 'looking_for' => 'succulents or a snake plant pup', 'location' => 'Kurunegala',
     'status' => 'open', 'exchanged_with' => null, 'exchanged_at' => null, 'photo' => 'mock/goldie.svg', 'created_at' => mock_date(-2) . ' 09:15:00',
     'species_id' => 2, 'common_name' => 'Golden pothos', 'scientific_name' => 'Epipremnum aureum',
     'username' => 'nimali.grows', 'full_name' => 'Nimali Perera', 'avatar' => null,
     'rating_avg' => 4.9, 'rating_count' => 23, 'interest_count' => 4, 'distance_km' => 3,
     'description' => 'Three healthy cuttings from my pothos Goldie, rooted in water for three weeks. Each has four to six leaves and roots about 5 cm long. Ready to pot.',
     'size' => '3 cuttings, 15 to 20 cm', 'plant_condition' => 'Healthy, rooted in water', 'share_email' => 1, 'share_phone' => 1],

    ['id' => 8, 'user_id' => 5, 'plant_id' => 61, 'title' => 'Aloe vera pups (4)',
     'type' => 'free', 'looking_for' => 'nothing, free to a good home', 'location' => 'Kurunegala',
     'status' => 'open', 'exchanged_with' => null, 'exchanged_at' => null, 'photo' => 'mock/vera.svg', 'created_at' => mock_date(-3) . ' 17:40:00',
     'species_id' => 5, 'common_name' => 'Aloe vera', 'scientific_name' => 'Aloe vera',
     'username' => 'ruwan.roots', 'full_name' => 'Ruwan Rathnayake', 'avatar' => null,
     'rating_avg' => 4.6, 'rating_count' => 9, 'interest_count' => 2, 'distance_km' => 5,
     'description' => 'Four pups from my mother aloe, each with its own small roots. Aloe is very easy on a sunny windowsill, so these are perfect for a beginner. Bring a small pot or a bag.',
     'size' => '4 pups, 8 to 12 cm tall', 'plant_condition' => 'Healthy, rooted, ready to pot', 'share_email' => 1, 'share_phone' => 1],

    // Swap or free: the owner takes a plant in return, or gives it to a beginner for nothing. It appears under both
    // the Swap and the Free filter. Nobody is interested yet, so its card has no "interested" count.
    ['id' => 12, 'user_id' => 5, 'plant_id' => 55, 'title' => 'Monstera cutting with a node',
     'type' => 'either', 'looking_for' => 'a pothos or a philodendron, or free to a beginner', 'location' => 'Kurunegala',
     'status' => 'open', 'exchanged_with' => null, 'exchanged_at' => null, 'photo' => 'mock/monty.svg', 'created_at' => mock_date(-4) . ' 12:10:00',
     'species_id' => 1, 'common_name' => 'Swiss cheese plant', 'scientific_name' => 'Monstera deliciosa',
     'username' => 'ruwan.roots', 'full_name' => 'Ruwan Rathnayake', 'avatar' => null,
     'rating_avg' => 4.6, 'rating_count' => 9, 'interest_count' => 0, 'distance_km' => 5,
     'description' => 'A top cutting from Leafy with one leaf, a node and a short aerial root. It roots easily in water or damp moss. Happy to swap for a pothos or a philodendron, or to give it to someone starting out.',
     'size' => '1 cutting with one leaf, about 25 cm', 'plant_condition' => 'Healthy, not rooted yet', 'share_email' => 0, 'share_phone' => 1],

    ['id' => 9, 'user_id' => 4, 'plant_id' => 59, 'title' => "Snake plant 'Laurentii'",
     'type' => 'swap', 'looking_for' => 'any fern', 'location' => 'Kuliyapitiya',
     'status' => 'open', 'exchanged_with' => null, 'exchanged_at' => null, 'photo' => 'mock/sergeant.svg', 'created_at' => mock_date(-5) . ' 08:05:00',
     'species_id' => 3, 'common_name' => 'Snake plant', 'scientific_name' => 'Dracaena trifasciata',
     'username' => 'dinesh.greens', 'full_name' => 'Dinesh Gunasekara', 'avatar' => null,
     'rating_avg' => 4.7, 'rating_count' => 15, 'interest_count' => 1, 'distance_km' => 18,
     'description' => 'A Laurentii pup with yellow edges, separated from Spike with its own roots and potted in a 10 cm pot. Snake plants forgive almost everything, even a missed watering.',
     'size' => '1 pup in a 10 cm pot, about 20 cm tall', 'plant_condition' => 'Healthy, potted in soil', 'share_email' => 1, 'share_phone' => 0],

    // Kandy is 42 km from Kurunegala, beyond the 25 km a member sees first: it proves the distance filter hides far
    // listings, and appears with "50 km" or "Any distance". Visitors see it, as they see every listing.
    ['id' => 13, 'user_id' => 3, 'plant_id' => 57, 'title' => 'Heartleaf philodendron cuttings (4)',
     'type' => 'swap', 'looking_for' => 'any hoya or a small fern', 'location' => 'Kandy',
     'status' => 'open', 'exchanged_with' => null, 'exchanged_at' => null, 'photo' => 'mock/goldie.svg', 'created_at' => mock_date(-6) . ' 19:20:00',
     'species_id' => 12, 'common_name' => 'Heartleaf philodendron', 'scientific_name' => 'Philodendron hederaceum',
     'username' => 'kandy.jungle', 'full_name' => 'Kavindi Jayawardena', 'avatar' => null,
     'rating_avg' => 5.0, 'rating_count' => 11, 'interest_count' => 1, 'distance_km' => 42,
     'description' => 'Four cuttings from my philodendron Hugo, two leaves each, rooting in a jar of water. The first roots are already showing. I can meet in Kandy town.',
     'size' => '4 cuttings, 15 to 20 cm', 'plant_condition' => 'Healthy, roots starting in water', 'share_email' => 1, 'share_phone' => 1],

    // p17: "Wariyapola · 14 km · 1 week ago", 3 interested. Kavindi (Kandy) keeps it: her bio promises seedlings to give away.
    ['id' => 10, 'user_id' => 3, 'plant_id' => null, 'title' => 'Tomato seedlings (6)',
     'type' => 'free', 'looking_for' => 'nothing, free to a good home', 'location' => 'Wariyapola',
     'status' => 'open', 'exchanged_with' => null, 'exchanged_at' => null, 'photo' => 'mock/tommy.svg', 'created_at' => mock_date(-7) . ' 18:30:00',
     'species_id' => 6, 'common_name' => 'Tomato', 'scientific_name' => 'Solanum lycopersicum',
     'username' => 'kandy.jungle', 'full_name' => 'Kavindi Jayawardena', 'avatar' => null,
     'rating_avg' => 5.0, 'rating_count' => 11, 'interest_count' => 3, 'distance_km' => 14,
     'description' => 'Six tomato seedlings grown from saved seed, about three weeks old, each in a small paper pot that can go straight into the ground. Pick-up in Wariyapola, where I spend the weekends.',
     'size' => '6 seedlings, about 10 cm tall', 'plant_condition' => 'Healthy, in paper pots', 'share_email' => 1, 'share_phone' => 0],

    // Nimali's second open listing, from wireframe p17 ("Peace lily division · Kurunegala · 1 km · 1 week ago"),
    // so her profile's "See listings (2)" matches p21. It offers her Lily (plant 48). looking_for is invented
    // (cut off on p17).
    ['id' => 6, 'user_id' => 1, 'plant_id' => 48, 'title' => 'Peace lily division',
     'type' => 'swap', 'looking_for' => 'a fern or a calathea', 'location' => 'Kurunegala',
     'status' => 'open', 'exchanged_with' => null, 'exchanged_at' => null, 'photo' => 'mock/lily.svg', 'created_at' => mock_date(-7) . ' 16:30:00',
     'species_id' => 7, 'common_name' => 'Peace lily', 'scientific_name' => 'Spathiphyllum wallisii',
     'username' => 'nimali.grows', 'full_name' => 'Nimali Perera', 'avatar' => null,
     'rating_avg' => 4.9, 'rating_count' => 23, 'interest_count' => 1, 'distance_km' => 1,
     'description' => 'A division from my peace lily Lily, with its own roots and five leaves. Potted in fresh soil last week, and a new leaf is already coming.',
     'size' => '1 division, about 30 cm tall', 'plant_condition' => 'Healthy, potted in soil', 'share_email' => 1, 'share_phone' => 0],

    // p17: "Echeveria cuttings (5) · Pannala · 22 km", 2 interested. Cuttings, so no plant row: the proposed species_id.
    ['id' => 11, 'user_id' => 4, 'plant_id' => null, 'title' => 'Echeveria cuttings (5)',
     'type' => 'swap', 'looking_for' => 'a string of pearls or any trailing succulent', 'location' => 'Pannala',
     'status' => 'open', 'exchanged_with' => null, 'exchanged_at' => null, 'photo' => 'mock/pearl.svg', 'created_at' => mock_date(-9) . ' 10:45:00',
     'species_id' => 8, 'common_name' => 'Mexican snowball', 'scientific_name' => 'Echeveria elegans',
     'username' => 'dinesh.greens', 'full_name' => 'Dinesh Gunasekara', 'avatar' => null,
     'rating_avg' => 4.7, 'rating_count' => 15, 'interest_count' => 2, 'distance_km' => 22,
     'description' => 'Five rosette cuttings from my echeveria, dried for a week and now growing tiny roots. Keep them dry and in bright light until they settle.',
     'size' => '5 rosettes, 3 to 5 cm across', 'plant_condition' => 'Healthy, roots starting', 'share_email' => 1, 'share_phone' => 0],

    // A finished give-away that is not rated yet, so My exchanges (P13) has an exchange waiting for Nimali's rating
    // (p20, element 6). Sergeant's two pups (its story: "Two new pups at the base") went to Amaya two days ago. A give-away
    // is not a swap, so no swap_count changes. p20 shows "Spider plant babies" with Amaya here, but that listing (3) was
    // rated both ways in July (the reviews below), so this one is invented, like its interests and dates.
    ['id' => 14, 'user_id' => 1, 'plant_id' => 44, 'title' => 'Snake plant pups (2)',
     'type' => 'free', 'looking_for' => 'nothing, free to a good home', 'location' => 'Kurunegala',
     'status' => 'closed', 'exchanged_with' => 2, 'exchanged_at' => mock_date(-2) . ' 18:00:00',
     'photo' => 'mock/sergeant.svg', 'created_at' => mock_date(-11) . ' 08:00:00',
     'species_id' => 3, 'common_name' => 'Snake plant', 'scientific_name' => 'Dracaena trifasciata',
     'username' => 'nimali.grows', 'full_name' => 'Nimali Perera', 'avatar' => null,
     'rating_avg' => 4.9, 'rating_count' => 23, 'interest_count' => 2, 'distance_km' => 1,
     'description' => 'Two pups from my snake plant Sergeant, each with its own roots, in small pots. Free to anyone starting out with plants.',
     'size' => '2 pups, about 15 cm tall', 'plant_condition' => 'Healthy, potted in soil', 'share_email' => 1, 'share_phone' => 1],

    // A finished swap, so the marketplace can prove that closed listings stay hidden (p17, UX notes). It is listing 5 of
    // the reviews below: Nimali swapped these cuttings with Dinesh (exchanged_with 4), and they rated each other on 14 Sep.
    ['id' => 5, 'user_id' => 1, 'plant_id' => null, 'title' => "Pothos 'Marble Queen' cuttings (3)",
     'type' => 'swap', 'looking_for' => 'a snake plant pup', 'location' => 'Kurunegala',
     'status' => 'closed', 'exchanged_with' => 4, 'exchanged_at' => '2026-09-13 17:00:00',
     'photo' => 'mock/goldie.svg', 'created_at' => '2026-09-06 10:00:00',
     'species_id' => 2, 'common_name' => 'Golden pothos', 'scientific_name' => 'Epipremnum aureum',
     'username' => 'nimali.grows', 'full_name' => 'Nimali Perera', 'avatar' => null,
     'rating_avg' => 4.9, 'rating_count' => 23, 'interest_count' => 3, 'distance_km' => 2,
     'description' => 'Three Marble Queen cuttings with cream and green leaves, rooted in water for a month.',
     'size' => '3 cuttings, about 15 cm', 'plant_condition' => 'Healthy, rooted in water', 'share_email' => 1, 'share_phone' => 1],
];

// What each listing is filed under, so swappers can find it with the Exchange filters (Create Listing asks for these).
// Accepted 11 Nov 2026 (database/README.md, stage F close): exchange_listings.light_level ENUM('low','medium','bright') NULL,
// exchange_listings.water_need ENUM('low','moderate','high') NULL, and a table listing_categories (listing_id, category),
// because one listing can be in several categories; stage B reads them back with GROUP_CONCAT as `categories`.
// All values are invented, chosen to match each plant (aloe and peace lily are not pet safe; echeveria is).
$listing_traits = [
    7  => [['indoor', 'air_purifying'],               'low',    'moderate'],   // Pothos 'Golden'
    8  => [['indoor', 'succulents'],                  'bright', 'low'],        // Aloe vera pups
    12 => [['indoor', 'air_purifying'],               'medium', 'moderate'],   // Monstera cutting
    9  => [['indoor', 'air_purifying'],               'low',    'low'],        // Snake plant 'Laurentii'
    13 => [['indoor', 'air_purifying'],               'low',    'moderate'],   // Heartleaf philodendron
    10 => [['outdoor', 'edible'],                     'bright', 'high'],       // Tomato seedlings
    6  => [['indoor', 'flowering', 'air_purifying'], 'low',    'high'],       // Peace lily
    11 => [['succulents', 'outdoor', 'pet_friendly'], 'bright', 'low'],        // Echeveria cuttings
    14 => [['indoor', 'air_purifying'],               'low',    'low'],        // Snake plant pups (closed)
    5  => [['indoor', 'air_purifying'],               'low',    'moderate'],   // Marble Queen (closed)
];
// km_from_town: how far the pick-up place is from its town's centre, 0 = in the town (proposed
// exchange_listings.km_from_town, set with the slider on Create Listing). Listing Detail shows it as "Pick-up: about 3 km
// from Kurunegala"; cards show the town only. The Kurunegala values are p17's printed distances, the others invented.
$listing_km = [7 => 3, 8 => 5, 12 => 5, 9 => 2, 13 => 4, 10 => 0, 6 => 1, 11 => 0, 14 => 1, 5 => 2];
foreach ($mock_listings as $index => $listing) {
    [$categories, $light, $water] = $listing_traits[$listing['id']] ?? [[], null, null];
    $mock_listings[$index] += ['categories' => $categories, 'light_level' => $light, 'water_need' => $water,
                               'km_from_town' => $listing_km[$listing['id']] ?? null];
}

// Towns with coordinates: rows of the `towns` table accepted in DES-07 (id, name, latitude, longitude), keyed by name.
// Sri Lanka's main cities (every district capital and a few large towns) plus the listings' own smaller towns
// (Kuliyapitiya, Pannala, Wariyapola). Coordinates are the town centres, rounded to 4 decimals. The Exchange tab's "Near"
// list shows them A–Z. Stage B: SELECT id, name, latitude, longitude FROM towns ORDER BY name.
$mock_towns = [];
foreach ([
    ['Ampara', 7.2975, 81.6820], ['Anuradhapura', 8.3114, 80.4037], ['Badulla', 6.9934, 81.0550],
    ['Batticaloa', 7.7310, 81.6747], ['Chilaw', 7.5758, 79.7953], ['Colombo', 6.9271, 79.8612],
    ['Dambulla', 7.8742, 80.6511], ['Galle', 6.0535, 80.2210], ['Gampaha', 7.0840, 80.0098],
    ['Hambantota', 6.1241, 81.1185], ['Jaffna', 9.6615, 80.0255], ['Kalutara', 6.5854, 79.9607],
    ['Kandy', 7.2906, 80.6337], ['Kegalle', 7.2513, 80.3464], ['Kilinochchi', 9.3803, 80.3770],
    ['Kuliyapitiya', 7.4688, 80.0401], ['Kurunegala', 7.4863, 80.3647], ['Mannar', 8.9810, 79.9044],
    ['Matale', 7.4675, 80.6234], ['Matara', 5.9549, 80.5550], ['Monaragala', 6.8728, 81.3507],
    ['Mullaitivu', 9.2671, 80.8142], ['Negombo', 7.2008, 79.8737], ['Nuwara Eliya', 6.9497, 80.7891],
    ['Pannala', 7.3297, 80.0181], ['Polonnaruwa', 7.9403, 81.0188], ['Puttalam', 8.0362, 79.8283],
    ['Ratnapura', 6.6828, 80.3992], ['Trincomalee', 8.5874, 81.2152], ['Vavuniya', 8.7514, 80.4971],
    ['Wariyapola', 7.6280, 80.2400],
] as $id => [$name, $latitude, $longitude]) {
    $mock_towns[$name] = ['id' => $id + 1, 'name' => $name, 'latitude' => $latitude, 'longitude' => $longitude];
}

// distance_km: how far each listing's town is from the viewer's town, in a straight line between the town centres
// (town_distance_km() in helpers.php; stage B works it out in the query with the same Haversine formula). Two listings
// in the viewer's own town are 0 km away, and the cards say "in town". A visitor has no town: NULL, and the pages hide
// the distance. (Until 11 Nov these were typed numbers from p17; the Community page now measures from any town.)
foreach ($mock_listings as $index => $listing) {
    $mock_listings[$index]['distance_km'] = $current_user
        ? town_distance_km($mock_towns, $current_user['location'], $listing['location'])
        : null;
}

// ── Listing Detail (P11, COM-08) ────────────────────────

// Extra photos for the gallery — rows of a proposed `listing_photos` table for DES-07 (id, listing_id, path, created_at),
// shaped like the optional plant_photos. The gallery shows the listing's own photo first (exchange_listings.photo, the
// card photo), then these, oldest first. p18 shows three photos of the pothos cuttings. Only placeholder drawings exist,
// so they repeat the card drawing; the aloe's second photo is invented. The other listings have only their card photo.
$mock_listing_photos = [
    ['id' => 301, 'listing_id' => 7, 'path' => 'mock/goldie.svg', 'created_at' => mock_date(-2) . ' 09:15:00'],
    ['id' => 302, 'listing_id' => 7, 'path' => 'mock/goldie.svg', 'created_at' => mock_date(-2) . ' 09:16:00'],
    ['id' => 303, 'listing_id' => 8, 'path' => 'mock/vera.svg',   'created_at' => mock_date(-3) . ' 17:40:00'],
];

// The interests Nimali has sent — rows of `listing_interests` with her user_id: the requester's side, which decides
// state A or B on Listing Detail. status is new (the owner has not opened it yet), seen, or withdrawn (the table plan's
// ➕ column); a withdrawn row no longer counts. Stage B: SELECT … FROM listing_interests WHERE listing_id = ? AND
// user_id = ?, with a proposed UNIQUE (listing_id, user_id) for DES-07, so one member has one row per listing.
// She asked Dinesh for his echeveria cuttings, which suits her own "looking for succulents", and yesterday Kavindi for
// philodendron cuttings (still 'new': Kavindi has not opened it yet). Messages and dates invented.
$mock_listing_interests = [
    ['id' => 16, 'listing_id' => 11, 'user_id' => 1,
     'message' => 'Hello! Could I have two of these for my balcony? I can bring a pothos cutting in return.',
     'status' => 'seen', 'created_at' => mock_date(-6) . ' 20:10:00'],
    ['id' => 22, 'listing_id' => 13, 'user_id' => 1,
     'message' => 'Hi Kavindi! Could I have two cuttings? I can bring a peace lily division in return.',
     'status' => 'new', 'created_at' => mock_date(-1) . ' 21:00:00'],
];

// ── Landing page (P01, CORE-27) ─────────────────────────

// ── Sign up and Log in (P02, CORE-07 and CORE-09) ───────

// The member quoted in the community panel (wireframe p9) — a users row plus plant_count, a COUNT() on plants.
// The quote itself is page text, not data: there is no testimonials table.
$mock_quote_member = [
    'id' => 3, 'username' => 'kandy.jungle', 'full_name' => 'Kavindi Jayawardena', 'avatar' => null,
    'location' => 'Kandy',
    'plant_count' => 64,
];

// ── Dashboard (P03, CORE-24 and CORE-25) ────────────────

// The Dashboard reads the rows above: $mock_reminders (Today's care), $mock_plant_reminders (All reminders),
// $mock_plants (the reminder popup) and $mock_collections (Garden at a glance).

// ── Plant Profile (P05, CORE-18 and CORE-21) ───────────

// The species guide — rows of `plant_species`, keyed by id so a plant finds its row with $mock_species[$species_id].
// light, water, humidity and notes come from the proposal; pet_safe and the two default intervals are the
// DES-07 additions (column names are my proposal until DES-13 writes database/species.csv).
// photo is a reference picture of the species, the "reference photo" beside the Plant Identifier's top match (p14,
// element 4): a proposed column for DES-13, each picture credited in docs/credits.md. Placeholder drawings for now.
// The default intervals fill in a new plant's reminders on the Add Plant form (P06).
$mock_species = [
    1 => ['id' => 1, 'common_name' => 'Swiss cheese plant', 'scientific_name' => 'Monstera deliciosa', 'photo' => 'mock/monty.svg',
          'light' => 'Bright, indirect', 'water' => 'When the top 5 cm of soil are dry', 'humidity' => '60%+ preferred',
          'pet_safe' => 0, 'water_interval_days' => 7, 'fertilise_interval_days' => 30,
          'notes' => 'Give it a moss pole to climb: the leaves grow bigger and split more as it climbs. Wipe the leaves now and then so they can take in light.'],
    2 => ['id' => 2, 'common_name' => 'Golden pothos', 'scientific_name' => 'Epipremnum aureum', 'photo' => 'mock/goldie.svg',
          'light' => 'Low to bright, indirect', 'water' => 'When the top half of the soil is dry', 'humidity' => '40%+',
          'pet_safe' => 0, 'water_interval_days' => 5, 'fertilise_interval_days' => 30,
          'notes' => 'Drooping leaves are its way of asking for water. Cuttings root easily in a glass of water.'],
    3 => ['id' => 3, 'common_name' => 'Snake plant', 'scientific_name' => 'Dracaena trifasciata', 'photo' => 'mock/sergeant.svg',
          'light' => 'Low to bright', 'water' => 'Only when the soil is fully dry', 'humidity' => 'Any',
          'pet_safe' => 0, 'water_interval_days' => 14, 'fertilise_interval_days' => 30,
          'notes' => 'Too much water is the usual way to lose one. Water less in the rainy season.'],
    4 => ['id' => 4, 'common_name' => 'Fiddle-leaf fig', 'scientific_name' => 'Ficus lyrata', 'photo' => 'mock/figgy.svg',
          'light' => 'Bright, indirect, some sun', 'water' => 'When the top 3 cm are dry', 'humidity' => '50%+',
          'pet_safe' => 0, 'water_interval_days' => 7, 'fertilise_interval_days' => 30,
          'notes' => 'Dislikes being moved: expect a dropped leaf or two after a change of place.'],
    5 => ['id' => 5, 'common_name' => 'Aloe vera', 'scientific_name' => 'Aloe vera', 'photo' => 'mock/vera.svg',
          'light' => 'Bright, direct sun', 'water' => 'Only when the soil is fully dry', 'humidity' => 'Low, dry air is fine',
          'pet_safe' => 0, 'water_interval_days' => 14, 'fertilise_interval_days' => 60,
          'notes' => 'Use a gritty, fast-draining mix and a pot with a hole. Pups can be potted up once they have roots.'],
    6 => ['id' => 6, 'common_name' => 'Tomato', 'scientific_name' => 'Solanum lycopersicum', 'photo' => 'mock/tommy.svg',
          'light' => 'Full sun, 6+ hours', 'water' => 'Keep the soil evenly moist', 'humidity' => 'Any',
          'pet_safe' => 0, 'water_interval_days' => 2, 'fertilise_interval_days' => 14,
          'notes' => 'Water the soil, not the leaves: wet leaves invite early blight. Remove the lowest leaves as the plant grows.'],
    7 => ['id' => 7, 'common_name' => 'Peace lily', 'scientific_name' => 'Spathiphyllum wallisii', 'photo' => 'mock/lily.svg',
          'light' => 'Low to medium, indirect', 'water' => 'When the leaves just begin to droop', 'humidity' => '50%+',
          'pet_safe' => 0, 'water_interval_days' => 4, 'fertilise_interval_days' => 45,
          'notes' => 'Brown leaf tips usually mean dry air or tap water with a lot of chlorine.'],
    8 => ['id' => 8, 'common_name' => 'Mexican snowball', 'scientific_name' => 'Echeveria elegans', 'photo' => 'mock/pearl.svg',
          'light' => 'Bright, some direct sun', 'water' => 'Only when the soil is fully dry', 'humidity' => 'Low',
          'pet_safe' => 1, 'water_interval_days' => 10, 'fertilise_interval_days' => 60,
          'notes' => 'Water the soil, never into the rosette. A stretched, leaning stem means it wants more light.'],

    // Species of other members' plants, for Explore (P09). The Thai Constellation is a cultivar of
    // Monstera deliciosa with its own row, because wireframe p16 prints its own scientific name on the card.
    9 => ['id' => 9, 'common_name' => 'Thai Constellation monstera', 'scientific_name' => "Monstera 'Thai Constellation'", 'photo' => 'mock/monty.svg',
          'light' => 'Bright, indirect', 'water' => 'When the top 5 cm of soil are dry', 'humidity' => '60%+ preferred',
          'pet_safe' => 0, 'water_interval_days' => 7, 'fertilise_interval_days' => 30,
          'notes' => 'The cream patches cannot make food, so it grows slower than a green monstera and needs more light.'],
    10 => ['id' => 10, 'common_name' => 'Swiss cheese vine', 'scientific_name' => 'Monstera adansonii', 'photo' => 'mock/monty.svg',
          'light' => 'Bright, indirect', 'water' => 'When the top 3 cm of soil are dry', 'humidity' => '60%+ preferred',
          'pet_safe' => 0, 'water_interval_days' => 6, 'fertilise_interval_days' => 30,
          'notes' => 'A climber or a trailer: give it a pole or let it hang. The holes appear as the leaves mature.'],
    11 => ['id' => 11, 'common_name' => 'Chinese money plant', 'scientific_name' => 'Pilea peperomioides', 'photo' => 'mock/pip.svg',
          'light' => 'Bright, indirect', 'water' => 'When the top half of the soil is dry', 'humidity' => '40%+',
          'pet_safe' => 1, 'water_interval_days' => 7, 'fertilise_interval_days' => 30,
          'notes' => 'Turn the pot a quarter each week so it grows straight. Its baby plants are easy to share.'],
    12 => ['id' => 12, 'common_name' => 'Heartleaf philodendron', 'scientific_name' => 'Philodendron hederaceum', 'photo' => 'mock/goldie.svg',
          'light' => 'Low to bright, indirect', 'water' => 'When the top 3 cm of soil are dry', 'humidity' => '40%+',
          'pet_safe' => 0, 'water_interval_days' => 6, 'fertilise_interval_days' => 30,
          'notes' => 'Pale new leaves mean it wants more light. Pinch the tips to keep it bushy.'],

    // The two "Other possibilities" of the Plant Identifier (P07, p14, element 7). Nobody owns one yet, so they only
    // appear there and in the species lists of the forms. Their care values are from general care guides; the
    // drawings are borrowed placeholders.
    13 => ['id' => 13, 'common_name' => 'Flamingo flower', 'scientific_name' => 'Anthurium andraeanum', 'photo' => 'mock/lily.svg',
          'light' => 'Bright, indirect', 'water' => 'When the top 3 cm of soil are dry', 'humidity' => '60%+ preferred',
          'pet_safe' => 0, 'water_interval_days' => 7, 'fertilise_interval_days' => 30,
          'notes' => 'The red "flowers" are waxy leaves that last for weeks. Wipe dust off the leaves to keep them shiny.'],
    14 => ['id' => 14, 'common_name' => 'Chinese evergreen', 'scientific_name' => 'Aglaonema commutatum', 'photo' => 'mock/goldie.svg',
          'light' => 'Low to medium, indirect', 'water' => 'When the top half of the soil is dry', 'humidity' => '40%+',
          'pet_safe' => 0, 'water_interval_days' => 7, 'fertilise_interval_days' => 45,
          'notes' => 'One of the most forgiving houseplants. Keep it away from cold draughts.'],
];

// Every reminder of Nimali's plants — rows of `care_reminders`. $mock_reminders above holds only today's three;
// these are all of them, with the same ids, and each keeps next_due = last_done + interval_days.
$mock_plant_reminders = [
    ['id' => 11, 'plant_id' => 42, 'task' => 'water',     'interval_days' => 7,  'last_done' => mock_date(-7),  'next_due' => mock_date(0)],
    ['id' => 14, 'plant_id' => 42, 'task' => 'fertilise', 'interval_days' => 30, 'last_done' => mock_date(-18), 'next_due' => mock_date(12)],
    ['id' => 12, 'plant_id' => 43, 'task' => 'water',     'interval_days' => 5,  'last_done' => mock_date(-7),  'next_due' => mock_date(-2)],
    ['id' => 13, 'plant_id' => 44, 'task' => 'fertilise', 'interval_days' => 30, 'last_done' => mock_date(-30), 'next_due' => mock_date(0)],
    ['id' => 15, 'plant_id' => 44, 'task' => 'water',     'interval_days' => 14, 'last_done' => mock_date(-10), 'next_due' => mock_date(4)],
    ['id' => 16, 'plant_id' => 45, 'task' => 'water',     'interval_days' => 7,  'last_done' => mock_date(-4),  'next_due' => mock_date(3)],
    ['id' => 17, 'plant_id' => 45, 'task' => 'fertilise', 'interval_days' => 30, 'last_done' => mock_date(-20), 'next_due' => mock_date(10)],
    ['id' => 18, 'plant_id' => 48, 'task' => 'water',     'interval_days' => 4,  'last_done' => mock_date(-3),  'next_due' => mock_date(1)],
    ['id' => 19, 'plant_id' => 46, 'task' => 'water',     'interval_days' => 14, 'last_done' => mock_date(-5),  'next_due' => mock_date(9)],
    ['id' => 20, 'plant_id' => 47, 'task' => 'water',     'interval_days' => 2,  'last_done' => mock_date(-1),  'next_due' => mock_date(1)],
    ['id' => 21, 'plant_id' => 47, 'task' => 'fertilise', 'interval_days' => 14, 'last_done' => mock_date(-7),  'next_due' => mock_date(7)],
    ['id' => 22, 'plant_id' => 49, 'task' => 'water',     'interval_days' => 10, 'last_done' => mock_date(-4),  'next_due' => mock_date(6)],
];

// Members as other people see them — users rows keyed by id, public columns only (never email or phone),
// plus the counts stage B calculates. The Plant Profile joins its owner from here, so the owner card still shows
// Nimali while you preview the page as a visitor. The Public Profile (P14) reads every column.
//   plant_count, collection_count — COUNT() of the member's PUBLIC plants and collections only: these numbers are
//                    shown to other people, and private things must not be counted for them either.
//                    Nimali has 18 plants in 3 collections; others see 13 in 2 (her Succulents are private).
//   swap_count     — completed swaps: closed listings of type swap the member took part in, as owner or as exchanged_with
//   rating_avg     — ROUND(AVG(score), 1) of the ratings the member received; rating_count — COUNT() of them.
//                    Both match the score counts in $mock_rating_counts below. A free give-away can be rated too,
//                    so a member can have more ratings than swaps.
// Towns and the plant counts and ratings of Amaya, Kavindi and Dinesh are from wireframe p16; Nimali's numbers and
// bio from p21. Ruwan's numbers, every swap_count and created_at, and the other bios are invented.
$mock_users = [
    1 => ['id' => 1, 'username' => 'nimali.grows', 'full_name' => 'Nimali Perera', 'avatar' => null,
          'bio' => 'Balcony gardener growing aroids and far too many pothos. Always happy to swap cuttings.',
          'location' => 'Kurunegala', 'created_at' => '2025-03-14 09:00:00',
          'plant_count' => 13, 'collection_count' => 2, 'swap_count' => 12, 'rating_avg' => 4.9, 'rating_count' => 23],
    2 => ['id' => 2, 'username' => 'aroid.amaya', 'full_name' => 'Amaya Madushani', 'avatar' => null,
          'bio' => 'Collecting aroids one cutting at a time in a small Colombo flat. Ask me about pilea babies.',
          'location' => 'Colombo', 'created_at' => '2024-11-02 19:30:00',
          'plant_count' => 42, 'collection_count' => 2, 'swap_count' => 10, 'rating_avg' => 4.8, 'rating_count' => 14],
    3 => ['id' => 3, 'username' => 'kandy.jungle', 'full_name' => 'Kavindi Jayawardena', 'avatar' => null,
          'bio' => 'Hill-country plant hoarder: aroids indoors, succulents on the shelf, tomato seedlings every season to give away.',
          'location' => 'Kandy', 'created_at' => '2024-08-21 07:15:00',
          'plant_count' => 64, 'collection_count' => 2, 'swap_count' => 8, 'rating_avg' => 5.0, 'rating_count' => 11],
    4 => ['id' => 4, 'username' => 'dinesh.greens', 'full_name' => 'Dinesh Gunasekara', 'avatar' => null,
          'bio' => 'Coastal gardener testing which plants can take the sea breeze. Snake plants never let me down.',
          'location' => 'Negombo', 'created_at' => '2025-01-09 12:00:00',
          'plant_count' => 37, 'collection_count' => 1, 'swap_count' => 13, 'rating_avg' => 4.7, 'rating_count' => 15],
    5 => ['id' => 5, 'username' => 'ruwan.roots', 'full_name' => 'Ruwan Rathnayake', 'avatar' => null,
          'bio' => 'Weekend gardener in Kurunegala. My mother aloe keeps making pups, so there are always some to share.',
          'location' => 'Kurunegala', 'created_at' => '2025-05-27 18:45:00',
          'plant_count' => 21, 'collection_count' => 2, 'swap_count' => 5, 'rating_avg' => 4.6, 'rating_count' => 9],
];

// ── Explore (P09, COM-01 to COM-03) ─────────────────────

// Other members' plants — rows of `plants` joined with plant_species (scientific_name, common_name) and
// collections (collection_name), shaped like $mock_plants but without reminders: those are the owner's own business.
// Searching "monstera" finds the six monsteras of wireframe p16 (Thai Con, Big Momma, Swiss, Monty, Leafy, Holey);
// the rest give the filters something to filter. Pip is the Landing page's featured plant. Whose Leafy and Holey are,
// the collections, dates and the plants after Holey are invented. Photos are placeholder drawings.
$mock_community_plants = [
    ['id' => 51, 'user_id' => 2, 'collection_id' => 21, 'species_id' => 11, 'nickname' => 'Pip',
     'photo' => 'mock/pip.svg', 'acquired_on' => '2025-02-14', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Pilea peperomioides', 'common_name' => 'Chinese money plant',
     'collection_name' => 'Windowsill', 'last_logged_at' => mock_date(-4) . ' 08:30:00'],

    ['id' => 52, 'user_id' => 3, 'collection_id' => 31, 'species_id' => 9, 'nickname' => 'Thai Con',
     'photo' => 'mock/monty.svg', 'acquired_on' => '2025-01-05', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => "Monstera 'Thai Constellation'", 'common_name' => 'Thai Constellation monstera',
     'collection_name' => 'Aroids', 'last_logged_at' => mock_date(-2) . ' 17:10:00'],

    ['id' => 53, 'user_id' => 2, 'collection_id' => 22, 'species_id' => 1, 'nickname' => 'Big Momma',
     'photo' => 'mock/monty.svg', 'acquired_on' => '2024-11-20', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Monstera deliciosa', 'common_name' => 'Swiss cheese plant',
     'collection_name' => 'Living room', 'last_logged_at' => mock_date(-6) . ' 19:00:00'],

    ['id' => 54, 'user_id' => 4, 'collection_id' => 41, 'species_id' => 10, 'nickname' => 'Swiss',
     'photo' => 'mock/monty.svg', 'acquired_on' => '2025-03-02', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Monstera adansonii', 'common_name' => 'Swiss cheese vine',
     'collection_name' => 'Hallway', 'last_logged_at' => mock_date(-8) . ' 07:50:00'],

    ['id' => 55, 'user_id' => 5, 'collection_id' => 51, 'species_id' => 1, 'nickname' => 'Leafy',
     'photo' => 'mock/monty.svg', 'acquired_on' => '2025-06-30', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Monstera deliciosa', 'common_name' => 'Swiss cheese plant',
     'collection_name' => 'Verandah', 'last_logged_at' => mock_date(-11) . ' 16:20:00'],

    ['id' => 56, 'user_id' => 2, 'collection_id' => 22, 'species_id' => 10, 'nickname' => 'Holey',
     'photo' => 'mock/monty.svg', 'acquired_on' => '2025-08-11', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Monstera adansonii', 'common_name' => 'Swiss cheese vine',
     'collection_name' => 'Living room', 'last_logged_at' => mock_date(-3) . ' 12:40:00'],

    ['id' => 57, 'user_id' => 3, 'collection_id' => 31, 'species_id' => 12, 'nickname' => 'Hugo',
     'photo' => 'mock/goldie.svg', 'acquired_on' => '2025-04-18', 'health_status' => 'stable', 'is_public' => 1,
     'scientific_name' => 'Philodendron hederaceum', 'common_name' => 'Heartleaf philodendron',
     'collection_name' => 'Aroids', 'last_logged_at' => mock_date(-14) . ' 09:30:00'],

    ['id' => 58, 'user_id' => 5, 'collection_id' => 51, 'species_id' => 2, 'nickname' => 'Marble',
     'photo' => 'mock/goldie.svg', 'acquired_on' => '2025-07-22', 'health_status' => 'needs_attention', 'is_public' => 1,
     'scientific_name' => 'Epipremnum aureum', 'common_name' => 'Golden pothos',
     'collection_name' => 'Verandah', 'last_logged_at' => mock_date(-2) . ' 18:00:00'],

    ['id' => 59, 'user_id' => 4, 'collection_id' => 41, 'species_id' => 3, 'nickname' => 'Spike',
     'photo' => 'mock/sergeant.svg', 'acquired_on' => '2025-02-01', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Dracaena trifasciata', 'common_name' => 'Snake plant',
     'collection_name' => 'Hallway', 'last_logged_at' => mock_date(-5) . ' 10:10:00'],

    ['id' => 60, 'user_id' => 3, 'collection_id' => 32, 'species_id' => 8, 'nickname' => 'Rosie',
     'photo' => 'mock/pearl.svg', 'acquired_on' => '2025-05-09', 'health_status' => 'stable', 'is_public' => 1,
     'scientific_name' => 'Echeveria elegans', 'common_name' => 'Mexican snowball',
     'collection_name' => 'Succulent shelf', 'last_logged_at' => mock_date(-18) . ' 15:00:00'],

    ['id' => 61, 'user_id' => 5, 'collection_id' => 52, 'species_id' => 5, 'nickname' => 'Mother Aloe',
     'photo' => 'mock/vera.svg', 'acquired_on' => '2024-12-12', 'health_status' => 'thriving', 'is_public' => 1,
     'scientific_name' => 'Aloe vera', 'common_name' => 'Aloe vera',
     'collection_name' => 'Garden', 'last_logged_at' => mock_date(-3) . ' 17:40:00'],
];

// ── Add a plant: the AI suggestion (P06, ML-13) ─────────

// The crops the disease model was trained on: the 14 plants of the PlantVillage dataset (ml-service/README.md).
// Stage M reads them from the model's class list (GET /health); p15's scope statement names six and "8 more".
// A plant is checked only when its species' common name is one of these.
$mock_supported_crops = ['Apple', 'Blueberry', 'Cherry', 'Corn', 'Grape', 'Orange', 'Peach', 'Pepper', 'Potato',
                         'Raspberry', 'Soybean', 'Squash', 'Strawberry', 'Tomato'];


// Likes per public plant: COUNT(*) on the optional plant_likes table (COM-15), keyed by plant id.
// Private plants (Vera, Pearl) cannot be seen by others, so they have none.
// Thai Con 87, Big Momma 48, Swiss 31, Monty 12, Leafy 9, Holey 7 are from wireframe p16; Pip's 48 from the Landing page.
$mock_like_counts = [
    42 => 12, 43 => 3, 44 => 7, 45 => 2, 48 => 4, 47 => 5,
    51 => 48, 52 => 87, 53 => 48, 54 => 31, 55 => 9, 56 => 7, 57 => 22, 58 => 5, 59 => 14, 60 => 11, 61 => 6,
];

// ── Public Profile (P14, COM-05 and COM-13) ─────────────

// Other members' collections — rows of `collections` with the same two calculated columns as $mock_collections
// (plant_count, cover_photos), using the ids $mock_community_plants already points at. plant_count counts public plants,
// and each member's public collections add up to their plant_count in $mock_users (Amaya 12 + 30 = 42).
// Every public collection holds at least one plant of the sample, so opening it on a profile never shows an empty list.
// Names, counts and the two private collections are invented. The collages borrow placeholder drawings for the
// plants that are not in the sample.
$mock_community_collections = [
    ['id' => 21, 'user_id' => 2, 'name' => 'Windowsill',       'is_public' => 1, 'plant_count' => 12,
     'cover_photos' => ['mock/pip.svg', 'mock/pearl.svg', 'mock/vera.svg']],
    ['id' => 22, 'user_id' => 2, 'name' => 'Living room',      'is_public' => 1, 'plant_count' => 30,
     'cover_photos' => ['mock/monty.svg', 'mock/monty.svg', 'mock/lily.svg']],   // Big Momma, Holey, then a borrowed drawing
    ['id' => 23, 'user_id' => 2, 'name' => 'Propagation box',  'is_public' => 0, 'plant_count' => 6,
     'cover_photos' => ['mock/goldie.svg', 'mock/pip.svg', 'mock/monty.svg']],
    ['id' => 31, 'user_id' => 3, 'name' => 'Aroids',           'is_public' => 1, 'plant_count' => 40,
     'cover_photos' => ['mock/monty.svg', 'mock/goldie.svg', 'mock/figgy.svg']],
    ['id' => 32, 'user_id' => 3, 'name' => 'Succulent shelf',  'is_public' => 1, 'plant_count' => 24,
     'cover_photos' => ['mock/pearl.svg', 'mock/vera.svg', 'mock/pearl.svg']],
    ['id' => 41, 'user_id' => 4, 'name' => 'Hallway',          'is_public' => 1, 'plant_count' => 37,
     'cover_photos' => ['mock/monty.svg', 'mock/sergeant.svg', 'mock/lily.svg']],
    ['id' => 42, 'user_id' => 4, 'name' => 'Propagation jars', 'is_public' => 0, 'plant_count' => 2,
     'cover_photos' => ['mock/goldie.svg', 'mock/sergeant.svg']],
    ['id' => 51, 'user_id' => 5, 'name' => 'Verandah',         'is_public' => 1, 'plant_count' => 14,
     'cover_photos' => ['mock/monty.svg', 'mock/goldie.svg', 'mock/figgy.svg']],
    ['id' => 52, 'user_id' => 5, 'name' => 'Garden',           'is_public' => 1, 'plant_count' => 7,
     'cover_photos' => ['mock/vera.svg', 'mock/tommy.svg', 'mock/sergeant.svg']],
];

// Written reviews — rows of `user_ratings` that have a comment, newest first, each joined with:
//   users             → rater_username, rater_full_name, rater_avatar (who wrote it)
//   exchange_listings → listing_title (what the two members exchanged)
// created_at is not in the table plan yet: a proposed user_ratings column for DES-07 (p21 dates the latest review).
// Stage B: … WHERE rated_id = ? AND comment IS NOT NULL ORDER BY created_at DESC.
// A rating belongs to one finished exchange (listing_id), and the two members may rate each other once each, so every
// listing appears twice. Listings 1 to 5 are Nimali's closed listings; $mock_listings holds the newest of them (5).
// Ratings without a comment are not rows here; they are counted in $mock_rating_counts.
// The newest rating of Nimali, from @dinesh.greens on 14 Sep, is the latest review on p21; the rest are invented.
$mock_user_ratings = [
    ['id' => 58, 'rater_id' => 1, 'rated_id' => 4, 'listing_id' => 5, 'score' => 5,
     'comment' => 'Friendly and on time, and the plant in return was exactly as described.',
     'created_at' => '2026-09-14 18:05:00',
     'rater_username' => 'nimali.grows', 'rater_full_name' => 'Nimali Perera', 'rater_avatar' => null,
     'listing_title' => "Pothos 'Marble Queen' cuttings (3)"],
    ['id' => 57, 'rater_id' => 4, 'rated_id' => 1, 'listing_id' => 5, 'score' => 5,
     'comment' => 'Healthy cuttings, well packed. Easy and friendly swap!',
     'created_at' => '2026-09-14 16:20:00',
     'rater_username' => 'dinesh.greens', 'rater_full_name' => 'Dinesh Gunasekara', 'rater_avatar' => null,
     'listing_title' => "Pothos 'Marble Queen' cuttings (3)"],

    ['id' => 51, 'rater_id' => 1, 'rated_id' => 3, 'listing_id' => 4, 'score' => 5,
     'comment' => 'Brought a beautiful echeveria in return and shared great tips for it. A real plant person!',
     'created_at' => '2026-08-30 12:40:00',
     'rater_username' => 'nimali.grows', 'rater_full_name' => 'Nimali Perera', 'rater_avatar' => null,
     'listing_title' => 'Monstera cutting with an aerial root'],
    ['id' => 50, 'rater_id' => 3, 'rated_id' => 1, 'listing_id' => 4, 'score' => 5,
     'comment' => 'Big, healthy cutting with a strong aerial root. It rooted in two weeks.',
     'created_at' => '2026-08-30 11:05:00',
     'rater_username' => 'kandy.jungle', 'rater_full_name' => 'Kavindi Jayawardena', 'rater_avatar' => null,
     'listing_title' => 'Monstera cutting with an aerial root'],

    ['id' => 44, 'rater_id' => 1, 'rated_id' => 2, 'listing_id' => 3, 'score' => 5,
     'comment' => 'Brought a lovely pilea baby in return, wrapped in damp paper for the bus ride.',
     'created_at' => '2026-07-19 17:30:00',
     'rater_username' => 'nimali.grows', 'rater_full_name' => 'Nimali Perera', 'rater_avatar' => null,
     'listing_title' => 'Spider plant babies (4)'],
    ['id' => 43, 'rater_id' => 2, 'rated_id' => 1, 'listing_id' => 3, 'score' => 4,
     'comment' => 'Nice plants and easy to arrange. One baby had a yellow leaf, but it settled in fast.',
     'created_at' => '2026-07-19 15:10:00',
     'rater_username' => 'aroid.amaya', 'rater_full_name' => 'Amaya Madushani', 'rater_avatar' => null,
     'listing_title' => 'Spider plant babies (4)'],

    ['id' => 37, 'rater_id' => 1, 'rated_id' => 5, 'listing_id' => 2, 'score' => 5,
     'comment' => 'Kind and patient, and even brought a pot for the cuttings.',
     'created_at' => '2026-06-02 19:00:00',
     'rater_username' => 'nimali.grows', 'rater_full_name' => 'Nimali Perera', 'rater_avatar' => null,
     'listing_title' => "Pothos 'Golden' cuttings (2)"],
    ['id' => 36, 'rater_id' => 5, 'rated_id' => 1, 'listing_id' => 2, 'score' => 3,
     'comment' => 'The cuttings were smaller than in the photo, but they all rooted in the end.',
     'created_at' => '2026-06-02 18:15:00',
     'rater_username' => 'ruwan.roots', 'rater_full_name' => 'Ruwan Rathnayake', 'rater_avatar' => null,
     'listing_title' => "Pothos 'Golden' cuttings (2)"],

    ['id' => 24, 'rater_id' => 1, 'rated_id' => 4, 'listing_id' => 1, 'score' => 5,
     'comment' => 'Quick replies and a healthy plant in return. Would swap again.',
     'created_at' => '2026-03-08 13:00:00',
     'rater_username' => 'nimali.grows', 'rater_full_name' => 'Nimali Perera', 'rater_avatar' => null,
     'listing_title' => 'Snake plant pup'],
    ['id' => 23, 'rater_id' => 4, 'rated_id' => 1, 'listing_id' => 1, 'score' => 5,
     'comment' => 'Strong little pup with its own roots. Smooth swap from start to finish.',
     'created_at' => '2026-03-08 11:45:00',
     'rater_username' => 'dinesh.greens', 'rater_full_name' => 'Dinesh Gunasekara', 'rater_avatar' => null,
     'listing_title' => 'Snake plant pup'],
];

// How many ratings of each score every member received: the result of
//   SELECT rated_id, score, COUNT(*) AS rating_count FROM user_ratings GROUP BY rated_id, score
// (the profile asks for one member: WHERE rated_id = ? GROUP BY score). A score nobody gave has no row; the page adds 0.
// Each member's rows add up to rating_count in $mock_users, and their average rounds to rating_avg.
// Nimali: (21×5 + 1×4 + 1×3) / 23 = 112 / 23 = 4.87, printed 4.9. Wireframe p21 shows 20 / 2 / 1, which averages 4.83
// and would print 4.8, so one 4★ became a 5★ to keep the 4.9 that p16, p17, p18 and p21 all print.
$mock_rating_counts = [
    ['rated_id' => 1, 'score' => 5, 'rating_count' => 21],
    ['rated_id' => 1, 'score' => 4, 'rating_count' => 1],
    ['rated_id' => 1, 'score' => 3, 'rating_count' => 1],
    ['rated_id' => 2, 'score' => 5, 'rating_count' => 11],   // Amaya: 67 / 14 = 4.79, printed 4.8
    ['rated_id' => 2, 'score' => 4, 'rating_count' => 3],
    ['rated_id' => 3, 'score' => 5, 'rating_count' => 11],   // Kavindi: 55 / 11 = 5.0
    ['rated_id' => 4, 'score' => 5, 'rating_count' => 11],   // Dinesh: 70 / 15 = 4.67, printed 4.7
    ['rated_id' => 4, 'score' => 4, 'rating_count' => 3],
    ['rated_id' => 4, 'score' => 3, 'rating_count' => 1],
    ['rated_id' => 5, 'score' => 5, 'rating_count' => 6],    // Ruwan: 41 / 9 = 4.56, printed 4.6
    ['rated_id' => 5, 'score' => 4, 'rating_count' => 2],
    ['rated_id' => 5, 'score' => 3, 'rating_count' => 1],
];

// ── My Exchanges (P13, COM-10) ──────────────────────────

// Interest in Nimali's own listings, newest first — rows of `listing_interests`, each joined with:
//   users             → username, full_name, avatar, rating_avg, rating_count of the person who is interested
//                       (p20, element 4: every row shows the requester's rating before the owner replies)
//   exchange_listings → title
// Stage B: … JOIN exchange_listings ON exchange_listings.id = listing_interests.listing_id
//          WHERE exchange_listings.user_id = ? AND listing_interests.status <> 'withdrawn' ORDER BY created_at DESC
// Each listing's rows add up to its interest_count in $mock_listings. Listing 7's four rows have the notes of p20 and
// all stay 'new' (the header bell's four): stage B marks rows seen when the owner opens them (p20, UX notes), so p20's
// mix of New and Seen comes after a first visit. The other rows are invented.
// The ratings are those of $mock_users (p20 prints 5.0 (4) and 4.8 (31), which disagree with p16 and p21).
$mock_received_interests = [
    ['id' => 21, 'listing_id' => 7, 'user_id' => 4, 'message' => 'Would love to swap for a snake plant pup!',
     'status' => 'new', 'created_at' => mock_date(0) . ' 08:20:00',
     'username' => 'dinesh.greens', 'full_name' => 'Dinesh Gunasekara', 'avatar' => null, 'rating_avg' => 4.7, 'rating_count' => 15,
     'title' => "Pothos 'Golden', 3 rooted cuttings"],
    ['id' => 20, 'listing_id' => 7, 'user_id' => 2, 'message' => 'I can offer an Echeveria cutting.',
     'status' => 'new', 'created_at' => mock_date(-1) . ' 19:05:00',
     'username' => 'aroid.amaya', 'full_name' => 'Amaya Madushani', 'avatar' => null, 'rating_avg' => 4.8, 'rating_count' => 14,
     'title' => "Pothos 'Golden', 3 rooted cuttings"],
    ['id' => 19, 'listing_id' => 7, 'user_id' => 5, 'message' => null,
     'status' => 'new', 'created_at' => mock_date(-1) . ' 11:30:00',
     'username' => 'ruwan.roots', 'full_name' => 'Ruwan Rathnayake', 'avatar' => null, 'rating_avg' => 4.6, 'rating_count' => 9,
     'title' => "Pothos 'Golden', 3 rooted cuttings"],
    ['id' => 18, 'listing_id' => 7, 'user_id' => 3, 'message' => 'Is one cutting still available?',
     'status' => 'new', 'created_at' => mock_date(-2) . ' 16:45:00',
     'username' => 'kandy.jungle', 'full_name' => 'Kavindi Jayawardena', 'avatar' => null, 'rating_avg' => 5.0, 'rating_count' => 11,
     'title' => "Pothos 'Golden', 3 rooted cuttings"],

    ['id' => 17, 'listing_id' => 6, 'user_id' => 3, 'message' => "I have a small bird's nest fern for you. Could we meet in Kurunegala on Saturday?",
     'status' => 'seen', 'created_at' => mock_date(-5) . ' 10:30:00',
     'username' => 'kandy.jungle', 'full_name' => 'Kavindi Jayawardena', 'avatar' => null, 'rating_avg' => 5.0, 'rating_count' => 11,
     'title' => 'Peace lily division'],

    ['id' => 15, 'listing_id' => 14, 'user_id' => 2, 'message' => 'Could I have both pups for my new shelf? I can pick them up this week.',
     'status' => 'seen', 'created_at' => mock_date(-9) . ' 09:40:00',
     'username' => 'aroid.amaya', 'full_name' => 'Amaya Madushani', 'avatar' => null, 'rating_avg' => 4.8, 'rating_count' => 14,
     'title' => 'Snake plant pups (2)'],
    ['id' => 14, 'listing_id' => 14, 'user_id' => 4, 'message' => 'One pup would be perfect for my hallway.',
     'status' => 'seen', 'created_at' => mock_date(-10) . ' 20:15:00',
     'username' => 'dinesh.greens', 'full_name' => 'Dinesh Gunasekara', 'avatar' => null, 'rating_avg' => 4.7, 'rating_count' => 15,
     'title' => 'Snake plant pups (2)'],

    ['id' => 12, 'listing_id' => 5, 'user_id' => 4, 'message' => 'I have a snake plant pup ready for you.',
     'status' => 'seen', 'created_at' => '2026-09-07 08:30:00',
     'username' => 'dinesh.greens', 'full_name' => 'Dinesh Gunasekara', 'avatar' => null, 'rating_avg' => 4.7, 'rating_count' => 15,
     'title' => "Pothos 'Marble Queen' cuttings (3)"],
    ['id' => 11, 'listing_id' => 5, 'user_id' => 3, 'message' => 'Lovely! Would one cutting be possible?',
     'status' => 'seen', 'created_at' => '2026-09-06 19:00:00',
     'username' => 'kandy.jungle', 'full_name' => 'Kavindi Jayawardena', 'avatar' => null, 'rating_avg' => 5.0, 'rating_count' => 11,
     'title' => "Pothos 'Marble Queen' cuttings (3)"],
    ['id' => 10, 'listing_id' => 5, 'user_id' => 5, 'message' => null,
     'status' => 'seen', 'created_at' => '2026-09-06 12:15:00',
     'username' => 'ruwan.roots', 'full_name' => 'Ruwan Rathnayake', 'avatar' => null, 'rating_avg' => 4.6, 'rating_count' => 9,
     'title' => "Pothos 'Marble Queen' cuttings (3)"],
];


// ── Stage B, step 3.1: the garden data comes from the database ─────────────────
// The arrays above keep their names and shapes, but are refilled from the database here (includes/garden-data.php),
// for whoever is logged in. Every page that reads them now shows real rows without changing. The preview code below
// (things added in the session) still lies on top until each form saves for real (steps 3.2 to 3.7).
require_once __DIR__ . '/garden-data.php';
$viewer_id             = $current_user['id'] ?? null;
$mock_species          = species_list();
$mock_like_counts      = like_counts();
$mock_community_plants = other_public_plants($viewer_id);
$mock_plants           = $viewer_id ? garden_plants($viewer_id) : [];
$mock_collections      = $viewer_id ? garden_collections($viewer_id) : [];
$mock_plant_reminders  = $viewer_id ? garden_reminders($viewer_id) : [];
$mock_reminders        = $viewer_id ? due_reminders($viewer_id) : [];
if ($viewer_id) {
    // Real totals instead of the typed ones (Nimali's 20 plants and 3 collections become the 12 and 3 she has)
    $current_user = array_merge($current_user, garden_counts($viewer_id));
}
// Every member, so any public plant finds its owner (Community cards, the Plant Profile's owner card), with their
// trader numbers (ratings received, finished swaps) from the database (exchange-data.php, step 4.1).
require_once __DIR__ . '/exchange-data.php';
$trader_numbers = trader_numbers();
$mock_users     = [];
foreach (public_members() as $member_id => $member) {
    $mock_users[$member_id] = $member + ($trader_numbers[$member_id] ?? ['swap_count' => 0, 'rating_avg' => 0.0, 'rating_count' => 0]);
}
if ($viewer_id) {
    $current_user = array_merge($current_user, $trader_numbers[$viewer_id] ?? [],
                                ['new_interest_count' => new_interest_count($viewer_id)]);
}

// ── Stage B, step 4.1: the exchange comes from the database ─────────────────
// Listings, their photos, interests sent and received, ratings, towns and other members' public collections. Contact
// details are not here at all: listing.php asks listing_contact() for them, which only answers the owner and a member
// with a live interest.
$mock_towns    = towns_by_name();
$mock_listings = all_listings();
// distance_km: from the viewer's town to the listing's town, centre to centre (Haversine, town_distance_km() in
// helpers.php); null for a visitor or a member without a town, and the pages then hide the distance
foreach ($mock_listings as $index => $listing) {
    $mock_listings[$index]['distance_km'] = !empty($current_user['location'])
        ? town_distance_km($mock_towns, $current_user['location'], $listing['location'])
        : null;
}
$mock_listing_photos        = all_listing_photos();
$mock_listing_interests     = $viewer_id ? sent_interests($viewer_id) : [];
$mock_received_interests    = $viewer_id ? received_interests($viewer_id) : [];
$mock_user_ratings          = ratings_with_names();                                 // every written review
$mock_rating_counts         = rating_counts();
$mock_community_collections = others_public_collections($viewer_id);
$mock_preview_ratings       = $viewer_id ? ratings_with_names($viewer_id) : [];   // the ratings the viewer gave

// Every public plant in the community, as Explore (P09) searches them: Nimali's public plants and everyone else's,
// each joined with its owner (users: username, full_name, avatar, location), plus two calculated columns:
//   like_count  — COUNT(*) on plant_likes
//   on_exchange — 1 when an open exchange listing offers this plant (EXISTS on exchange_listings)
//   listing_id, listing_type — that open listing's id and type (swap, free or either), null when there is none,
//                  so the Community card's "Swap"/"Free" badge can link to it. Stage B: LEFT JOIN exchange_listings
//                  ON exchange_listings.plant_id = plants.id AND exchange_listings.status = 'open'.
// Stage B: one query with
// WHERE plants.is_public = 1, so a private plant can never reach the page.
$open_listing_by_plant = [];
foreach ($mock_listings as $listing) {
    if ($listing['status'] === 'open' && $listing['plant_id'] !== null) {
        $open_listing_by_plant[$listing['plant_id']] = $listing;
    }
}
$listed_plant_ids = array_keys($open_listing_by_plant);

$mock_public_plants = [];
foreach (array_merge($mock_plants, $mock_community_plants) as $plant) {
    if (!$plant['is_public']) {
        continue;
    }
    $owner = $mock_users[$plant['user_id']];
    $mock_public_plants[] = array_merge($plant, [
        'username'     => $owner['username'],
        'full_name'    => $owner['full_name'],
        'avatar'       => $owner['avatar'],
        'location'     => $owner['location'],
        'like_count'   => $mock_like_counts[$plant['id']] ?? 0,
        'on_exchange'  => in_array($plant['id'], $listed_plant_ids, true) ? 1 : 0,
        'listing_id'   => $open_listing_by_plant[$plant['id']]['id'] ?? null,
        'listing_type' => $open_listing_by_plant[$plant['id']]['type'] ?? null,
    ]);
}
