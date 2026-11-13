-- PlantFolio demo data: the wireframe sample content from includes/mock-data.php as real rows (CORE-02).
-- Load it after database/schema.sql. Running it again empties every table and loads the data afresh,
-- so it is also the reset script.
--
-- Demo logins: any sample member, e.g. nimali.grows, with the demo password PlantFolio#2026.
-- (A sample password for local demos only; real members choose their own.)
--
-- Ids are the mock data's ids, so links such as plant.php?id=42 (Monty) keep working after wiring.
-- Dates near today are written as CURDATE() - INTERVAL n DAY, so "due today" and "2 days ago" stay true on any day.
-- Generated from includes/mock-data.php on 2026-11-11; counts that the mock data only typed
-- (e.g. 23 ratings, 87 likes) become the real counts of these rows.

SET NAMES utf8mb4;
-- Strict mode: a wrong value stops the import instead of being stored as a warning.
SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- Everything below runs in one transaction: if any statement fails, the database is left as it was.
START TRANSACTION;

-- Empty every table, children first, so no foreign key is ever broken. (DELETE, not TRUNCATE: phpMyAdmin switches
-- foreign key checks back on before each statement, and TRUNCATE refuses a table that others point at.)
DELETE FROM care_guides;
DELETE FROM listing_categories;
DELETE FROM user_ratings;
DELETE FROM listing_interests;
DELETE FROM listing_photos;
DELETE FROM exchange_listings;
DELETE FROM disease_guides;
DELETE FROM plant_likes;
DELETE FROM care_reminders;
DELETE FROM health_logs;
DELETE FROM ml_predictions;
DELETE FROM plant_photos;
DELETE FROM plants;
DELETE FROM plant_species;
DELETE FROM collections;
DELETE FROM remember_tokens;
DELETE FROM users;
DELETE FROM towns;

-- towns: 31 town centres (every district capital plus the listings' smaller towns), from $mock_towns
INSERT INTO towns (id, name, latitude, longitude) VALUES
    (1, 'Ampara', 7.2975, 81.682),
    (2, 'Anuradhapura', 8.3114, 80.4037),
    (3, 'Badulla', 6.9934, 81.055),
    (4, 'Batticaloa', 7.731, 81.6747),
    (5, 'Chilaw', 7.5758, 79.7953),
    (6, 'Colombo', 6.9271, 79.8612),
    (7, 'Dambulla', 7.8742, 80.6511),
    (8, 'Galle', 6.0535, 80.221),
    (9, 'Gampaha', 7.084, 80.0098),
    (10, 'Hambantota', 6.1241, 81.1185),
    (11, 'Jaffna', 9.6615, 80.0255),
    (12, 'Kalutara', 6.5854, 79.9607),
    (13, 'Kandy', 7.2906, 80.6337),
    (14, 'Kegalle', 7.2513, 80.3464),
    (15, 'Kilinochchi', 9.3803, 80.377),
    (16, 'Kuliyapitiya', 7.4688, 80.0401),
    (17, 'Kurunegala', 7.4863, 80.3647),
    (18, 'Mannar', 8.981, 79.9044),
    (19, 'Matale', 7.4675, 80.6234),
    (20, 'Matara', 5.9549, 80.555),
    (21, 'Monaragala', 6.8728, 81.3507),
    (22, 'Mullaitivu', 9.2671, 80.8142),
    (23, 'Negombo', 7.2008, 79.8737),
    (24, 'Nuwara Eliya', 6.9497, 80.7891),
    (25, 'Pannala', 7.3297, 80.0181),
    (26, 'Polonnaruwa', 7.9403, 81.0188),
    (27, 'Puttalam', 8.0362, 79.8283),
    (28, 'Ratnapura', 6.6828, 80.3992),
    (29, 'Trincomalee', 8.5874, 81.2152),
    (30, 'Vavuniya', 8.7514, 80.4971),
    (31, 'Wariyapola', 7.628, 80.24);

-- users: the five sample members. Every account's password is the demo password PlantFolio#2026 (only its hash is stored)
INSERT INTO users (id, username, full_name, email, phone, password_hash, bio, town_id, km_from_town, avatar, created_at) VALUES
    (1, 'nimali.grows', 'Nimali Perera', 'nimali.p@example.com', '+94 77 000 1234', '$2y$10$FIlv17gVIH19XBZkjeJYmer3AvLRWHyqyPR5/Eifw8Geaf9sMPePG', 'Balcony gardener growing aroids and far too many pothos. Always happy to swap cuttings.', 17, 3, NULL, '2025-03-14 09:00:00'),
    (2, 'aroid.amaya', 'Amaya Madushani', 'amaya.m@example.com', '+94 76 000 2345', '$2y$10$FIlv17gVIH19XBZkjeJYmer3AvLRWHyqyPR5/Eifw8Geaf9sMPePG', 'Collecting aroids one cutting at a time in a small Colombo flat. Ask me about pilea babies.', 6, 0, NULL, '2024-11-02 19:30:00'),
    (3, 'kandy.jungle', 'Kavindi Jayawardena', 'kavindi.j@example.com', '+94 71 000 3456', '$2y$10$FIlv17gVIH19XBZkjeJYmer3AvLRWHyqyPR5/Eifw8Geaf9sMPePG', 'Hill-country plant hoarder: aroids indoors, succulents on the shelf, tomato seedlings every season to give away.', 13, 0, NULL, '2024-08-21 07:15:00'),
    (4, 'dinesh.greens', 'Dinesh Gunasekara', 'dinesh.g@example.com', NULL, '$2y$10$FIlv17gVIH19XBZkjeJYmer3AvLRWHyqyPR5/Eifw8Geaf9sMPePG', 'Coastal gardener testing which plants can take the sea breeze. Snake plants never let me down.', 23, 0, NULL, '2025-01-09 12:00:00'),
    (5, 'ruwan.roots', 'Ruwan Rathnayake', 'ruwan.r@example.com', '+94 70 000 5678', '$2y$10$FIlv17gVIH19XBZkjeJYmer3AvLRWHyqyPR5/Eifw8Geaf9sMPePG', 'Weekend gardener in Kurunegala. My mother aloe keeps making pups, so there are always some to share.', 17, 0, NULL, '2025-05-27 18:45:00');

-- plant_species: the 14 species of $mock_species (species.csv, DES-13, can replace them later)
INSERT INTO plant_species (id, common_name, scientific_name, photo, light, water, humidity, pet_safe, water_interval_days, fertilise_interval_days, notes) VALUES
    (1, 'Swiss cheese plant', 'Monstera deliciosa', 'mock/monty.svg', 'Bright, indirect', 'When the top 5 cm of soil are dry', '60%+ preferred', 0, 7, 30, 'Give it a moss pole to climb: the leaves grow bigger and split more as it climbs. Wipe the leaves now and then so they can take in light.'),
    (2, 'Golden pothos', 'Epipremnum aureum', 'mock/goldie.svg', 'Low to bright, indirect', 'When the top half of the soil is dry', '40%+', 0, 5, 30, 'Drooping leaves are its way of asking for water. Cuttings root easily in a glass of water.'),
    (3, 'Snake plant', 'Dracaena trifasciata', 'mock/sergeant.svg', 'Low to bright', 'Only when the soil is fully dry', 'Any', 0, 14, 30, 'Too much water is the usual way to lose one. Water less in the rainy season.'),
    (4, 'Fiddle-leaf fig', 'Ficus lyrata', 'mock/figgy.svg', 'Bright, indirect, some sun', 'When the top 3 cm are dry', '50%+', 0, 7, 30, 'Dislikes being moved: expect a dropped leaf or two after a change of place.'),
    (5, 'Aloe vera', 'Aloe vera', 'mock/vera.svg', 'Bright, direct sun', 'Only when the soil is fully dry', 'Low, dry air is fine', 0, 14, 60, 'Use a gritty, fast-draining mix and a pot with a hole. Pups can be potted up once they have roots.'),
    (6, 'Tomato', 'Solanum lycopersicum', 'mock/tommy.svg', 'Full sun, 6+ hours', 'Keep the soil evenly moist', 'Any', 0, 2, 14, 'Water the soil, not the leaves: wet leaves invite early blight. Remove the lowest leaves as the plant grows.'),
    (7, 'Peace lily', 'Spathiphyllum wallisii', 'mock/lily.svg', 'Low to medium, indirect', 'When the leaves just begin to droop', '50%+', 0, 4, 45, 'Brown leaf tips usually mean dry air or tap water with a lot of chlorine.'),
    (8, 'Mexican snowball', 'Echeveria elegans', 'mock/pearl.svg', 'Bright, some direct sun', 'Only when the soil is fully dry', 'Low', 1, 10, 60, 'Water the soil, never into the rosette. A stretched, leaning stem means it wants more light.'),
    (9, 'Thai Constellation monstera', 'Monstera ''Thai Constellation''', 'mock/monty.svg', 'Bright, indirect', 'When the top 5 cm of soil are dry', '60%+ preferred', 0, 7, 30, 'The cream patches cannot make food, so it grows slower than a green monstera and needs more light.'),
    (10, 'Swiss cheese vine', 'Monstera adansonii', 'mock/monty.svg', 'Bright, indirect', 'When the top 3 cm of soil are dry', '60%+ preferred', 0, 6, 30, 'A climber or a trailer: give it a pole or let it hang. The holes appear as the leaves mature.'),
    (11, 'Chinese money plant', 'Pilea peperomioides', 'mock/pip.svg', 'Bright, indirect', 'When the top half of the soil is dry', '40%+', 1, 7, 30, 'Turn the pot a quarter each week so it grows straight. Its baby plants are easy to share.'),
    (12, 'Heartleaf philodendron', 'Philodendron hederaceum', 'mock/goldie.svg', 'Low to bright, indirect', 'When the top 3 cm of soil are dry', '40%+', 0, 6, 30, 'Pale new leaves mean it wants more light. Pinch the tips to keep it bushy.'),
    (13, 'Flamingo flower', 'Anthurium andraeanum', 'mock/lily.svg', 'Bright, indirect', 'When the top 3 cm of soil are dry', '60%+ preferred', 0, 7, 30, 'The red "flowers" are waxy leaves that last for weeks. Wipe dust off the leaves to keep them shiny.'),
    (14, 'Chinese evergreen', 'Aglaonema commutatum', 'mock/goldie.svg', 'Low to medium, indirect', 'When the top half of the soil is dry', '40%+', 0, 7, 45, 'One of the most forgiving houseplants. Keep it away from cold draughts.');

-- collections: Nimali's three (1-3) and the other members' (21-52)
INSERT INTO collections (id, user_id, name, is_public) VALUES
    (1, 1, 'Living room', 1),
    (2, 1, 'Balcony', 1),
    (3, 1, 'Succulents', 0),
    (21, 2, 'Windowsill', 1),
    (22, 2, 'Living room', 1),
    (23, 2, 'Propagation box', 0),
    (31, 3, 'Aroids', 1),
    (32, 3, 'Succulent shelf', 1),
    (41, 4, 'Hallway', 1),
    (42, 4, 'Propagation jars', 0),
    (51, 5, 'Verandah', 1),
    (52, 5, 'Garden', 1);

-- plants: Nimali's 12 (42-49, 62-65) and 11 of other members (51-61). Same ids as the mock data, so plant.php?id=42 is still Monty
INSERT INTO plants (id, user_id, collection_id, species_id, custom_species, nickname, photo, acquired_on, health_status, is_public, created_at) VALUES
    (42, 1, 1, 1, NULL, 'Monty', 'mock/monty.svg', '2025-03-20', 'thriving', 1, '2025-03-20 10:00:00'),
    (43, 1, 1, 2, NULL, 'Goldie', 'mock/goldie.svg', '2025-04-02', 'needs_attention', 1, '2025-04-02 10:00:00'),
    (44, 1, 1, 3, NULL, 'Sergeant', 'mock/sergeant.svg', '2025-05-11', 'thriving', 1, '2025-05-11 10:00:00'),
    (45, 1, 1, 4, NULL, 'Figgy', 'mock/figgy.svg', '2025-06-18', 'stable', 1, '2025-06-18 10:00:00'),
    (64, 1, 1, 3, NULL, 'Stripes', 'mock/sergeant.svg', '2025-05-11', 'sick', 1, '2025-05-11 10:00:00'),
    (65, 1, 1, 3, NULL, 'Slim', 'mock/sergeant.svg', '2025-05-11', 'thriving', 1, '2025-05-11 10:00:00'),
    (48, 1, 1, 7, NULL, 'Lily', 'mock/lily.svg', '2025-09-02', 'stable', 1, '2025-09-02 10:00:00'),
    (46, 1, 3, 5, NULL, 'Vera', 'mock/vera.svg', '2025-07-06', 'thriving', 0, '2025-07-06 10:00:00'),
    (47, 1, 2, 6, NULL, 'Tommy', 'mock/tommy.svg', '2025-08-23', 'sick', 1, '2025-08-23 10:00:00'),
    (49, 1, 3, 8, NULL, 'Pearl', 'mock/pearl.svg', '2025-09-27', 'thriving', 0, '2025-09-27 10:00:00'),
    (62, 1, NULL, 11, NULL, 'Penny', 'mock/pip.svg', CURDATE() - INTERVAL 6 DAY, 'stable', 0, TIMESTAMP(CURDATE() - INTERVAL 6 DAY, '10:00:00')),
    (63, 1, NULL, 12, NULL, 'Heidi', 'mock/goldie.svg', CURDATE() - INTERVAL 4 DAY, 'thriving', 0, TIMESTAMP(CURDATE() - INTERVAL 4 DAY, '10:00:00')),
    (51, 2, 21, 11, NULL, 'Pip', 'mock/pip.svg', '2025-02-14', 'thriving', 1, '2025-02-14 10:00:00'),
    (52, 3, 31, 9, NULL, 'Thai Con', 'mock/monty.svg', '2025-01-05', 'thriving', 1, '2025-01-05 10:00:00'),
    (53, 2, 22, 1, NULL, 'Big Momma', 'mock/monty.svg', '2024-11-20', 'thriving', 1, '2024-11-20 10:00:00'),
    (54, 4, 41, 10, NULL, 'Swiss', 'mock/monty.svg', '2025-03-02', 'thriving', 1, '2025-03-02 10:00:00'),
    (55, 5, 51, 1, NULL, 'Leafy', 'mock/monty.svg', '2025-06-30', 'thriving', 1, '2025-06-30 10:00:00'),
    (56, 2, 22, 10, NULL, 'Holey', 'mock/monty.svg', '2025-08-11', 'thriving', 1, '2025-08-11 10:00:00'),
    (57, 3, 31, 12, NULL, 'Hugo', 'mock/goldie.svg', '2025-04-18', 'stable', 1, '2025-04-18 10:00:00'),
    (58, 5, 51, 2, NULL, 'Marble', 'mock/goldie.svg', '2025-07-22', 'needs_attention', 1, '2025-07-22 10:00:00'),
    (59, 4, 41, 3, NULL, 'Spike', 'mock/sergeant.svg', '2025-02-01', 'thriving', 1, '2025-02-01 10:00:00'),
    (60, 3, 32, 8, NULL, 'Rosie', 'mock/pearl.svg', '2025-05-09', 'stable', 1, '2025-05-09 10:00:00'),
    (61, 5, 52, 5, NULL, 'Mother Aloe', 'mock/vera.svg', '2024-12-12', 'thriving', 1, '2024-12-12 10:00:00');

-- plant_photos: extra gallery photos
INSERT INTO plant_photos (id, plant_id, path, created_at) VALUES
    (204, 42, 'mock/monty.svg', TIMESTAMP(CURDATE() - INTERVAL 5 DAY, '18:20:00')),
    (203, 42, 'mock/monty.svg', TIMESTAMP(CURDATE() - INTERVAL 31 DAY, '16:00:00')),
    (202, 42, 'mock/monty.svg', '2026-01-10 09:00:00'),
    (201, 42, 'mock/monty.svg', '2025-03-20 10:00:00'),
    (210, 47, 'mock/tommy.svg', TIMESTAMP(CURDATE() - INTERVAL 1 DAY, '07:46:00')),
    (211, 46, 'mock/vera.svg', TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '16:00:00'));

-- ml_predictions: 1, 3, 4 are behind Story entries; 5-6 are Plant Identifier results, 7-9 Health Check results (identify.php?prediction=5)
INSERT INTO ml_predictions (id, user_id, plant_id, type, predicted_label, confidence, alternatives, image_path, upload_name, upload_size, model_version, created_at) VALUES
    (1, 1, 42, 'species', 'Monstera deliciosa', 0.91, NULL, 'mock/monty.svg', NULL, NULL, 'species-mobilenetv2-v1', '2025-03-20 09:55:00'),
    (3, 1, 44, 'species', 'Dracaena trifasciata', 0.96, NULL, 'mock/sergeant.svg', NULL, NULL, 'species-mobilenetv2-v1', TIMESTAMP(CURDATE() - INTERVAL 40 DAY, '10:20:00')),
    (4, 1, 47, 'disease', 'Tomato___Early_blight', 0.92, NULL, 'mock/tommy.svg', NULL, NULL, 'disease-mobilenetv2-v1', TIMESTAMP(CURDATE() - INTERVAL 1 DAY, '07:45:00')),
    (5, 1, NULL, 'species', 'Spathiphyllum wallisii', 0.88, '[{"label":"Anthurium andraeanum","confidence":0.07},{"label":"Aglaonema commutatum","confidence":0.03}]', 'mock/lily.svg', 'lily.jpg', 1992294, 'species-mobilenetv2-v1', NOW() - INTERVAL 3 MINUTE),
    (6, 1, NULL, 'species', 'Spathiphyllum wallisii', 0.42, '[{"label":"Anthurium andraeanum","confidence":0.31},{"label":"Aglaonema commutatum","confidence":0.14}]', 'mock/lily.svg', 'IMG_2041.jpg', 3250586, 'species-mobilenetv2-v1', NOW() - INTERVAL 2 MINUTE),
    (7, 1, 47, 'disease', 'Tomato___Early_blight', 0.92, '[{"label":"Tomato___Septoria_leaf_spot","confidence":0.05},{"label":"Tomato___healthy","confidence":0.02}]', 'mock/tomato-leaf.svg', 'tommy-lower-leaf.jpg', 2306867, 'disease-mobilenetv2-v1', NOW() - INTERVAL 4 MINUTE),
    (8, 1, 47, 'disease', 'Tomato___Septoria_leaf_spot', 0.44, '[{"label":"Tomato___Early_blight","confidence":0.38},{"label":"Tomato___Bacterial_spot","confidence":0.11}]', 'mock/tomato-leaf.svg', 'IMG_2077.jpg', 3565158, 'disease-mobilenetv2-v1', NOW() - INTERVAL 3 MINUTE),
    (9, 1, 42, 'disease', 'Pepper,_bell___Bacterial_spot', 0.37, '[{"label":"Tomato___Target_Spot","confidence":0.21},{"label":"Grape___Esca_(Black_Measles)","confidence":0.12}]', 'mock/monty.svg', 'monty-leaf.jpg', 1887437, 'disease-mobilenetv2-v1', NOW() - INTERVAL 2 MINUTE);

-- health_logs: the plant Stories. Care entries have no status and no note (NULL)
INSERT INTO health_logs (id, plant_id, prediction_id, type, care_task, status, source, note, photo, logged_at) VALUES
    (61, 42, 1, 'milestone', NULL, 'thriving', 'ml', 'Added to garden.', 'mock/monty.svg', '2025-03-20 10:00:00'),
    (62, 43, NULL, 'milestone', NULL, 'thriving', 'manual', 'Added to garden.', 'mock/goldie.svg', '2025-04-02 11:30:00'),
    (63, 44, NULL, 'milestone', NULL, 'stable', 'manual', 'Added to garden.', 'mock/sergeant.svg', '2025-05-11 15:00:00'),
    (64, 45, NULL, 'milestone', NULL, 'thriving', 'manual', 'Added to garden.', 'mock/figgy.svg', '2025-06-18 12:00:00'),
    (65, 46, NULL, 'milestone', NULL, 'thriving', 'manual', 'Added to garden.', 'mock/vera.svg', '2025-07-06 09:30:00'),
    (66, 47, NULL, 'milestone', NULL, 'stable', 'manual', 'Added to garden.', 'mock/tommy.svg', '2025-08-23 08:00:00'),
    (70, 48, NULL, 'milestone', NULL, 'stable', 'manual', 'Added to garden.', 'mock/lily.svg', '2025-09-02 10:00:00'),
    (71, 49, NULL, 'milestone', NULL, 'thriving', 'manual', 'Added to garden.', 'mock/pearl.svg', '2025-09-27 11:00:00'),
    (88, 44, 3, 'note', NULL, 'thriving', 'ml', 'Checked the species with the Plant Identifier.', 'mock/sergeant.svg', TIMESTAMP(CURDATE() - INTERVAL 40 DAY, '10:20:00')),
    (90, 49, NULL, 'note', NULL, 'thriving', 'manual', 'Moved to the sunniest windowsill.', NULL, TIMESTAMP(CURDATE() - INTERVAL 26 DAY, '10:15:00')),
    (91, 46, NULL, 'photo', NULL, 'thriving', 'manual', 'Four pups ready to pot up.', 'mock/vera.svg', TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '16:00:00')),
    (93, 44, NULL, 'note', NULL, 'thriving', 'manual', 'Two new pups at the base.', NULL, TIMESTAMP(CURDATE() - INTERVAL 12 DAY, '09:00:00')),
    (94, 48, NULL, 'note', NULL, 'stable', 'manual', 'Drooped on Sunday, perked up an hour after watering.', NULL, TIMESTAMP(CURDATE() - INTERVAL 15 DAY, '08:40:00')),
    (95, 42, NULL, 'note', NULL, 'thriving', 'manual', 'Repotted into a 25 cm terracotta pot with chunky aroid mix.', NULL, TIMESTAMP(CURDATE() - INTERVAL 31 DAY, '16:00:00')),
    (96, 45, NULL, 'note', NULL, 'stable', 'manual', 'Dropped one lower leaf after the move to the window.', NULL, TIMESTAMP(CURDATE() - INTERVAL 9 DAY, '17:30:00')),
    (98, 47, NULL, 'note', NULL, 'stable', 'manual', 'Fruit setting on the first truss.', NULL, TIMESTAMP(CURDATE() - INTERVAL 6 DAY, '17:00:00')),
    (99, 43, NULL, 'note', NULL, 'needs_attention', 'manual', 'Leaves may start to droop.', NULL, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '18:10:00')),
    (101, 42, NULL, 'photo', NULL, 'thriving', 'manual', 'New leaf unfurled, the biggest one yet, with four splits.', 'mock/monty.svg', TIMESTAMP(CURDATE() - INTERVAL 5 DAY, '18:20:00')),
    (102, 47, 4, 'health_check', NULL, 'sick', 'ml', 'Brown rings on the lower leaves.', 'mock/tommy.svg', TIMESTAMP(CURDATE() - INTERVAL 1 DAY, '07:46:00')),
    (103, 47, NULL, 'care', 'water', NULL, 'manual', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 1 DAY, '07:30:00')),
    (104, 47, NULL, 'care', 'water', NULL, 'manual', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '07:40:00')),
    (105, 47, NULL, 'care', 'fertilise', NULL, 'manual', 'Tomato feed, half strength.', NULL, TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '08:00:00')),
    (106, 42, NULL, 'care', 'water', NULL, 'manual', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '18:00:00')),
    (107, 42, NULL, 'care', 'water', NULL, 'manual', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 14 DAY, '18:10:00')),
    (108, 42, NULL, 'care', 'fertilise', NULL, 'manual', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 18 DAY, '17:00:00'));

-- care_reminders: every reminder keeps next_due = last_done + interval_days, counted from today
INSERT INTO care_reminders (id, plant_id, task, repeat_type, interval_days, last_done, next_due) VALUES
    (11, 42, 'water', 'interval', 7, CURDATE() - INTERVAL 7 DAY, CURDATE()),
    (12, 43, 'water', 'interval', 5, CURDATE() - INTERVAL 7 DAY, CURDATE() - INTERVAL 2 DAY),
    (13, 44, 'fertilise', 'interval', 30, CURDATE() - INTERVAL 30 DAY, CURDATE()),
    (14, 42, 'fertilise', 'interval', 30, CURDATE() - INTERVAL 18 DAY, CURDATE() + INTERVAL 12 DAY),
    (15, 44, 'water', 'interval', 14, CURDATE() - INTERVAL 10 DAY, CURDATE() + INTERVAL 4 DAY),
    (16, 45, 'water', 'interval', 7, CURDATE() - INTERVAL 4 DAY, CURDATE() + INTERVAL 3 DAY),
    (17, 45, 'fertilise', 'interval', 30, CURDATE() - INTERVAL 20 DAY, CURDATE() + INTERVAL 10 DAY),
    (18, 48, 'water', 'interval', 4, CURDATE() - INTERVAL 3 DAY, CURDATE() + INTERVAL 1 DAY),
    (19, 46, 'water', 'interval', 14, CURDATE() - INTERVAL 5 DAY, CURDATE() + INTERVAL 9 DAY),
    (20, 47, 'water', 'interval', 2, CURDATE() - INTERVAL 1 DAY, CURDATE() + INTERVAL 1 DAY),
    (21, 47, 'fertilise', 'interval', 14, CURDATE() - INTERVAL 7 DAY, CURDATE() + INTERVAL 7 DAY),
    (22, 49, 'water', 'interval', 10, CURDATE() - INTERVAL 4 DAY, CURDATE() + INTERVAL 6 DAY),
    (23, 64, 'water', 'interval', 14, CURDATE() - INTERVAL 15 DAY, CURDATE() - INTERVAL 1 DAY),
    (24, 65, 'water', 'interval', 14, CURDATE() - INTERVAL 9 DAY, CURDATE() + INTERVAL 5 DAY);

-- plant_likes: only five members exist, so a plant has at most 4 likes; plants with more mock likes get more
INSERT INTO plant_likes (id, plant_id, user_id) VALUES
    (1, 42, 2),
    (2, 42, 3),
    (3, 42, 4),
    (4, 42, 5),
    (5, 43, 2),
    (6, 43, 3),
    (7, 43, 4),
    (8, 44, 2),
    (9, 44, 3),
    (10, 44, 4),
    (11, 44, 5),
    (12, 45, 2),
    (13, 45, 3),
    (14, 48, 2),
    (15, 48, 3),
    (16, 48, 4),
    (17, 48, 5),
    (18, 47, 2),
    (19, 47, 3),
    (20, 47, 4),
    (21, 47, 5),
    (22, 51, 1),
    (23, 51, 3),
    (24, 51, 4),
    (25, 51, 5),
    (26, 52, 1),
    (27, 52, 2),
    (28, 52, 4),
    (29, 52, 5),
    (30, 53, 1),
    (31, 53, 3),
    (32, 53, 4),
    (33, 53, 5),
    (34, 54, 1),
    (35, 54, 2),
    (36, 54, 3),
    (37, 54, 5),
    (38, 55, 1),
    (39, 55, 2),
    (40, 55, 3),
    (41, 55, 4),
    (42, 56, 1),
    (43, 56, 3),
    (44, 56, 4),
    (45, 56, 5),
    (46, 57, 1),
    (47, 57, 2),
    (48, 57, 4),
    (49, 57, 5),
    (50, 58, 1),
    (51, 58, 2),
    (52, 58, 3),
    (53, 58, 4),
    (54, 59, 1),
    (55, 59, 2),
    (56, 59, 3),
    (57, 59, 5),
    (58, 60, 1),
    (59, 60, 2),
    (60, 60, 4),
    (61, 60, 5),
    (62, 61, 1),
    (63, 61, 2),
    (64, 61, 3),
    (65, 61, 4);

-- disease_guides: what the Health Check says for each model class
INSERT INTO disease_guides (id, class_label, crop, cause, symptoms, steps, status) VALUES
    (1, 'Tomato___Early_blight', 'Tomato', 'Caused by the fungus Alternaria solani', 'Dark brown spots with rings, like a target, on older lower leaves. Yellowing around the spots is common.', '["Remove the spotted lower leaves and bin them. Do not compost them.","Water at the base in the morning and keep the leaves dry.","Give plants more space so air can move between them.","If spots keep spreading, ask a local agriculture officer about a suitable fungicide."]', 'sick'),
    (2, 'Tomato___Septoria_leaf_spot', 'Tomato', 'Caused by the fungus Septoria lycopersici', 'Many small round spots with grey centres and dark edges, starting on the lowest leaves.', '["Pick off the spotted leaves and bin them.","Water the soil, not the leaves.","Spread mulch under the plant so soil does not splash onto the leaves.","If it spreads to the upper leaves, ask a local agriculture officer for advice."]', 'sick'),
    (3, 'Tomato___Bacterial_spot', 'Tomato', 'Caused by Xanthomonas bacteria', 'Small dark, greasy-looking spots on leaves and fruit, sometimes with a yellow edge.', '["Remove the worst leaves and wash your hands and tools afterwards.","Keep the leaves dry: water at the base in the morning.","Do not work with the plants while they are wet.","Ask a local agriculture officer before using any spray."]', 'sick'),
    (4, 'Tomato___healthy', 'Tomato', 'No disease found', 'The leaf shows none of the diseases the model knows.', '["Keep watering at the base, not over the leaves.","Remove yellowing lower leaves as the plant grows.","Check the lower leaves again every week or two.","Log a photo now and then, so changes are easy to spot."]', 'thriving');

-- exchange_listings: 8 open and 2 closed from the mock data (5-14), plus Nimali's four older closed swaps (1-4)
INSERT INTO exchange_listings (id, user_id, plant_id, species_id, title, description, type, looking_for, town_id, km_from_town, size, plant_condition, light_level, water_need, photo, share_email, share_phone, status, exchanged_with, exchanged_at, created_at) VALUES
    (1, 1, NULL, 3, 'Snake plant pup', 'A strong snake plant pup with its own roots.', 'swap', 'a pothos or a philodendron', 17, 1, NULL, NULL, NULL, NULL, 'mock/sergeant.svg', 1, 1, 'closed', 4, '2026-03-07 17:00:00', '2026-03-01 09:00:00'),
    (2, 1, NULL, 2, 'Pothos ''Golden'' cuttings (2)', 'Two pothos cuttings from Goldie, rooted in water.', 'swap', 'a monstera cutting', 17, 1, NULL, NULL, NULL, NULL, 'mock/goldie.svg', 1, 1, 'closed', 5, '2026-06-01 17:00:00', '2026-05-25 10:00:00'),
    (3, 1, NULL, NULL, 'Spider plant babies (4)', 'Four spider plant babies with small roots.', 'swap', 'a pilea baby', 17, 1, NULL, NULL, NULL, NULL, 'mock/pip.svg', 1, 1, 'closed', 2, '2026-07-18 16:00:00', '2026-07-10 10:00:00'),
    (4, 1, NULL, 1, 'Monstera cutting with an aerial root', 'A monstera cutting with one node and a long aerial root.', 'swap', 'any succulent', 17, 1, NULL, NULL, NULL, NULL, 'mock/monty.svg', 1, 1, 'closed', 3, '2026-08-29 15:00:00', '2026-08-20 10:00:00'),
    (5, 1, NULL, 2, 'Pothos ''Marble Queen'' cuttings (3)', 'Three Marble Queen cuttings with cream and green leaves, rooted in water for a month.', 'swap', 'a snake plant pup', 17, 2, '3 cuttings, about 15 cm', 'Healthy, rooted in water', 'low', 'moderate', 'mock/goldie.svg', 1, 1, 'closed', 4, '2026-09-13 17:00:00', '2026-09-06 10:00:00'),
    (6, 1, 48, 7, 'Peace lily division', 'A division from my peace lily Lily, with its own roots and five leaves. Potted in fresh soil last week, and a new leaf is already coming.', 'swap', 'a fern or a calathea', 17, 1, '1 division, about 30 cm tall', 'Healthy, potted in soil', 'low', 'high', 'mock/lily.svg', 1, 0, 'open', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '16:30:00')),
    (7, 1, 43, 2, 'Pothos ''Golden'', 3 rooted cuttings', 'Three healthy cuttings from my pothos Goldie, rooted in water for three weeks. Each has four to six leaves and roots about 5 cm long. Ready to pot.', 'swap', 'succulents or a snake plant pup', 17, 3, '3 cuttings, 15 to 20 cm', 'Healthy, rooted in water', 'low', 'moderate', 'mock/goldie.svg', 1, 1, 'open', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 2 DAY, '09:15:00')),
    (8, 5, 61, 5, 'Aloe vera pups (4)', 'Four pups from my mother aloe, each with its own small roots. Aloe is very easy on a sunny windowsill, so these are perfect for a beginner. Bring a small pot or a bag.', 'free', 'nothing, free to a good home', 17, 5, '4 pups, 8 to 12 cm tall', 'Healthy, rooted, ready to pot', 'bright', 'low', 'mock/vera.svg', 1, 1, 'open', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '17:40:00')),
    (9, 4, 59, 3, 'Snake plant ''Laurentii''', 'A Laurentii pup with yellow edges, separated from Spike with its own roots and potted in a 10 cm pot. Snake plants forgive almost everything, even a missed watering.', 'swap', 'any fern', 16, 2, '1 pup in a 10 cm pot, about 20 cm tall', 'Healthy, potted in soil', 'low', 'low', 'mock/sergeant.svg', 1, 0, 'open', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 5 DAY, '08:05:00')),
    (10, 3, NULL, 6, 'Tomato seedlings (6)', 'Six tomato seedlings grown from saved seed, about three weeks old, each in a small paper pot that can go straight into the ground. Pick-up in Wariyapola, where I spend the weekends.', 'free', 'nothing, free to a good home', 31, 0, '6 seedlings, about 10 cm tall', 'Healthy, in paper pots', 'bright', 'high', 'mock/tommy.svg', 1, 0, 'open', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 7 DAY, '18:30:00')),
    (11, 4, NULL, 8, 'Echeveria cuttings (5)', 'Five rosette cuttings from my echeveria, dried for a week and now growing tiny roots. Keep them dry and in bright light until they settle.', 'swap', 'a string of pearls or any trailing succulent', 25, 0, '5 rosettes, 3 to 5 cm across', 'Healthy, roots starting', 'bright', 'low', 'mock/pearl.svg', 1, 0, 'open', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 9 DAY, '10:45:00')),
    (12, 5, 55, 1, 'Monstera cutting with a node', 'A top cutting from Leafy with one leaf, a node and a short aerial root. It roots easily in water or damp moss. Happy to swap for a pothos or a philodendron, or to give it to someone starting out.', 'either', 'a pothos or a philodendron, or free to a beginner', 17, 5, '1 cutting with one leaf, about 25 cm', 'Healthy, not rooted yet', 'medium', 'moderate', 'mock/monty.svg', 0, 1, 'open', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 4 DAY, '12:10:00')),
    (13, 3, 57, 12, 'Heartleaf philodendron cuttings (4)', 'Four cuttings from my philodendron Hugo, two leaves each, rooting in a jar of water. The first roots are already showing. I can meet in Kandy town.', 'swap', 'any hoya or a small fern', 13, 4, '4 cuttings, 15 to 20 cm', 'Healthy, roots starting in water', 'low', 'moderate', 'mock/goldie.svg', 1, 1, 'open', NULL, NULL, TIMESTAMP(CURDATE() - INTERVAL 6 DAY, '19:20:00')),
    (14, 1, 44, 3, 'Snake plant pups (2)', 'Two pups from my snake plant Sergeant, each with its own roots, in small pots. Free to anyone starting out with plants.', 'free', 'nothing, free to a good home', 17, 1, '2 pups, about 15 cm tall', 'Healthy, potted in soil', 'low', 'low', 'mock/sergeant.svg', 1, 1, 'closed', 2, TIMESTAMP(CURDATE() - INTERVAL 2 DAY, '18:00:00'), TIMESTAMP(CURDATE() - INTERVAL 11 DAY, '08:00:00'));

-- listing_photos: extra gallery photos
INSERT INTO listing_photos (id, listing_id, path, created_at) VALUES
    (301, 7, 'mock/goldie.svg', TIMESTAMP(CURDATE() - INTERVAL 2 DAY, '09:15:00')),
    (302, 7, 'mock/goldie.svg', TIMESTAMP(CURDATE() - INTERVAL 2 DAY, '09:16:00')),
    (303, 8, 'mock/vera.svg', TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '17:40:00'));

-- listing_categories: every listing has at least one
INSERT INTO listing_categories (listing_id, category) VALUES
    (1, 'indoor'),
    (2, 'indoor'),
    (3, 'indoor'),
    (4, 'indoor'),
    (5, 'air_purifying'),
    (5, 'indoor'),
    (6, 'air_purifying'),
    (6, 'flowering'),
    (6, 'indoor'),
    (7, 'air_purifying'),
    (7, 'indoor'),
    (8, 'indoor'),
    (8, 'succulents'),
    (9, 'air_purifying'),
    (9, 'indoor'),
    (10, 'edible'),
    (10, 'outdoor'),
    (11, 'outdoor'),
    (11, 'pet_friendly'),
    (11, 'succulents'),
    (12, 'air_purifying'),
    (12, 'indoor'),
    (13, 'air_purifying'),
    (13, 'indoor'),
    (14, 'air_purifying'),
    (14, 'indoor');

-- listing_interests: Nimali's two (16, 22), the ones on her listings (10-21), and the rows behind other listings' counts (30-40)
INSERT INTO listing_interests (id, listing_id, user_id, message, status, created_at) VALUES
    (10, 5, 5, NULL, 'seen', '2026-09-06 12:15:00'),
    (11, 5, 3, 'Lovely! Would one cutting be possible?', 'seen', '2026-09-06 19:00:00'),
    (12, 5, 4, 'I have a snake plant pup ready for you.', 'seen', '2026-09-07 08:30:00'),
    (14, 14, 4, 'One pup would be perfect for my hallway.', 'seen', TIMESTAMP(CURDATE() - INTERVAL 10 DAY, '20:15:00')),
    (15, 14, 2, 'Could I have both pups for my new shelf? I can pick them up this week.', 'seen', TIMESTAMP(CURDATE() - INTERVAL 9 DAY, '09:40:00')),
    (16, 11, 1, 'Hello! Could I have two of these for my balcony? I can bring a pothos cutting in return.', 'seen', TIMESTAMP(CURDATE() - INTERVAL 6 DAY, '20:10:00')),
    (17, 6, 3, 'I have a small bird''s nest fern for you. Could we meet in Kurunegala on Saturday?', 'seen', TIMESTAMP(CURDATE() - INTERVAL 5 DAY, '10:30:00')),
    (18, 7, 3, 'Is one cutting still available?', 'new', TIMESTAMP(CURDATE() - INTERVAL 2 DAY, '16:45:00')),
    (19, 7, 5, NULL, 'new', TIMESTAMP(CURDATE() - INTERVAL 1 DAY, '11:30:00')),
    (20, 7, 2, 'I can offer an Echeveria cutting.', 'new', TIMESTAMP(CURDATE() - INTERVAL 1 DAY, '19:05:00')),
    (21, 7, 4, 'Would love to swap for a snake plant pup!', 'new', TIMESTAMP(CURDATE(), '08:20:00')),
    (22, 13, 1, 'Hi Kavindi! Could I have two cuttings? I can bring a peace lily division in return.', 'new', TIMESTAMP(CURDATE() - INTERVAL 1 DAY, '21:00:00')),
    (30, 8, 2, NULL, 'new', TIMESTAMP(CURDATE() - INTERVAL 2 DAY, '12:00:00')),
    (31, 8, 3, NULL, 'new', TIMESTAMP(CURDATE() - INTERVAL 1 DAY, '12:00:00')),
    (32, 9, 2, NULL, 'new', TIMESTAMP(CURDATE() - INTERVAL 4 DAY, '12:00:00')),
    (33, 10, 2, NULL, 'new', TIMESTAMP(CURDATE() - INTERVAL 6 DAY, '12:00:00')),
    (34, 10, 4, NULL, 'new', TIMESTAMP(CURDATE() - INTERVAL 5 DAY, '12:00:00')),
    (35, 10, 5, NULL, 'new', TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '12:00:00')),
    (36, 11, 5, NULL, 'new', TIMESTAMP(CURDATE() - INTERVAL 8 DAY, '12:00:00')),
    (37, 1, 4, NULL, 'seen', '2026-03-02 09:00:00'),
    (38, 2, 5, NULL, 'seen', '2026-05-26 10:00:00'),
    (39, 3, 2, NULL, 'seen', '2026-07-11 10:00:00'),
    (40, 4, 3, NULL, 'seen', '2026-08-21 10:00:00');

-- user_ratings: both sides of Nimali's five finished swaps (listings 1-5). Listing 14 is waiting for her rating
INSERT INTO user_ratings (id, rater_id, rated_id, listing_id, score, comment, created_at) VALUES
    (23, 4, 1, 1, 5, 'Strong little pup with its own roots. Smooth swap from start to finish.', '2026-03-08 11:45:00'),
    (24, 1, 4, 1, 5, 'Quick replies and a healthy plant in return. Would swap again.', '2026-03-08 13:00:00'),
    (36, 5, 1, 2, 3, 'The cuttings were smaller than in the photo, but they all rooted in the end.', '2026-06-02 18:15:00'),
    (37, 1, 5, 2, 5, 'Kind and patient, and even brought a pot for the cuttings.', '2026-06-02 19:00:00'),
    (43, 2, 1, 3, 4, 'Nice plants and easy to arrange. One baby had a yellow leaf, but it settled in fast.', '2026-07-19 15:10:00'),
    (44, 1, 2, 3, 5, 'Brought a lovely pilea baby in return, wrapped in damp paper for the bus ride.', '2026-07-19 17:30:00'),
    (50, 3, 1, 4, 5, 'Big, healthy cutting with a strong aerial root. It rooted in two weeks.', '2026-08-30 11:05:00'),
    (51, 1, 3, 4, 5, 'Brought a beautiful echeveria in return and shared great tips for it. A real plant person!', '2026-08-30 12:40:00'),
    (57, 4, 1, 5, 5, 'Healthy cuttings, well packed. Easy and friendly swap!', '2026-09-14 16:20:00'),
    (58, 1, 4, 5, 5, 'Friendly and on time, and the plant in return was exactly as described.', '2026-09-14 18:05:00');

-- care_guides: 8 cached AI guides. Pilea and philodendron have none on purpose, so Penny and Heidi show "Generating"
INSERT INTO care_guides (id, species, guide_json, model_used, created_at) VALUES
    (1, 'monstera deliciosa', '{"light":"Bright, indirect light near a window. Direct afternoon sun can scorch the leaves.","soil_fertilizer":"A chunky, well-draining aroid mix with bark and perlite. Feed with a balanced liquid fertiliser once a month while it is growing.","watering":"Water when the top 5 cm of soil are dry and let the extra drain away. Never leave the pot standing in water.","disease_control":"Soggy soil causes root rot, so watering less is the best defence. Check under the leaves for spider mites and wipe them off.","structure_pruning":"Give it a moss pole to climb so it grows upright. Cut yellow or damaged leaves off at the base with clean scissors."}', 'ollama:llama3.2', TIMESTAMP(CURDATE() - INTERVAL 9 DAY, '09:00:00')),
    (2, 'epipremnum aureum', '{"light":"Copes with low light, but keeps its golden streaks best in bright, indirect light.","soil_fertilizer":"Ordinary potting mix with some perlite for drainage. Feed lightly once a month in the growing season.","watering":"Water when the top half of the soil is dry. Drooping leaves usually mean it is thirsty.","disease_control":"Yellow, soft stems point to overwatering and root rot. Mealybugs look like white fluff: wipe them off with a cotton bud.","structure_pruning":"Pinch back long vines to make it bushier. The cut pieces root easily in a glass of water."}', 'ollama:llama3.2', TIMESTAMP(CURDATE() - INTERVAL 8 DAY, '09:00:00')),
    (3, 'dracaena trifasciata', '{"light":"Happy in low light or bright, indirect light. A few hours of gentle sun is fine.","soil_fertilizer":"A free-draining cactus or succulent mix. Feed only two or three times a year, in the growing season.","watering":"Water only when the soil is completely dry, about every two weeks. Water even less in the rainy season.","disease_control":"Too much water makes the base of the leaves soft and mushy. Remove those leaves and let the soil dry out.","structure_pruning":"Cut damaged leaves off at soil level. When the pot gets crowded, divide the plant and pot the pups on their own."}', 'ollama:llama3.2', TIMESTAMP(CURDATE() - INTERVAL 12 DAY, '09:00:00')),
    (4, 'ficus lyrata', '{"light":"Bright light near a window with some gentle morning sun. Turn the pot now and then so it grows evenly.","soil_fertilizer":"Well-draining potting mix with bark or perlite. Feed with a liquid fertiliser once a month while it is growing.","watering":"Water well when the top 3 cm are dry, then let it drain. Brown patches on the leaves often mean too much water.","disease_control":"Cut off leaves with spreading brown patches and water less. Wipe the leaves so dust and pests do not settle.","structure_pruning":"Cut just above a leaf to make it branch. It dislikes being moved, so pick a spot and keep it there."}', 'ollama:llama3.2', TIMESTAMP(CURDATE() - INTERVAL 15 DAY, '09:00:00')),
    (5, 'aloe vera', '{"light":"Plenty of bright light, including some direct sun. Too little light makes it stretch and lean.","soil_fertilizer":"Sandy cactus mix in a pot with a drainage hole. Feed once or twice a year at most.","watering":"Water deeply, then wait until the soil is completely dry. Keep it out of constant rain in the rainy season.","disease_control":"Mushy brown leaves mean rot from too much water. Scale insects look like small bumps: scrape them off.","structure_pruning":"Cut dry outer leaves off at the base. Pot up the pups around it once they have a few leaves."}', 'ollama:llama3.2', TIMESTAMP(CURDATE() - INTERVAL 20 DAY, '09:00:00')),
    (6, 'solanum lycopersicum', '{"light":"Full sun, at least 6 to 8 hours a day. Less sun means fewer flowers and fruit.","soil_fertilizer":"Rich, well-draining soil with compost mixed in. Once flowers appear, feed every two weeks with a fertiliser high in potassium.","watering":"Water deeply at the base in the morning, keeping the soil evenly moist. Uneven watering can crack the fruit.","disease_control":"Keep the leaves dry and remove spotted lower leaves to slow early blight. Leave space between plants so air can move.","structure_pruning":"Tie the stem to a stake as it grows. Pinch out the side shoots so the plant puts its energy into fruit."}', 'ollama:llama3.2', TIMESTAMP(CURDATE() - INTERVAL 3 DAY, '09:00:00')),
    (7, 'spathiphyllum wallisii', '{"light":"Medium to bright, indirect light. Direct sun burns the leaves.","soil_fertilizer":"Potting mix that holds some moisture, with a little perlite. Feed with a diluted fertiliser every six to eight weeks.","watering":"Keep the soil lightly moist. It droops when thirsty and perks up soon after watering.","disease_control":"Yellow leaves in soggy soil point to root rot, so let the top dry a little. Wipe the leaves to keep spider mites away.","structure_pruning":"Cut faded flower stalks off at the base. Remove yellow leaves where they meet the soil."}', 'ollama:llama3.2', TIMESTAMP(CURDATE() - INTERVAL 15 DAY, '09:00:00')),
    (8, 'echeveria elegans', '{"light":"Bright light with a few hours of direct sun keeps the rosette tight and colourful.","soil_fertilizer":"Gritty cactus mix in a pot with a drainage hole. Feed with a diluted cactus fertiliser two or three times a year.","watering":"Soak the soil, then wait until it is completely dry. Water the soil, not into the rosette.","disease_control":"Rot starts where water sits in the rosette, so keep the centre dry. Look for mealybugs between the leaves.","structure_pruning":"Pull off dry lower leaves. A stretched, leaning plant can be cut and its top replanted."}', 'ollama:llama3.2', TIMESTAMP(CURDATE() - INTERVAL 26 DAY, '09:00:00'));


COMMIT;
