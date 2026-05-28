-- ═══════════════════════════════════════════════════════════════
-- Family Tree Research — MariaDB 10.6 Schema
-- Run in phpMyAdmin → SQL, or via:  mysql -u user -p dbname < schema.sql
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ───────────────────────────────────────────────────────────────
-- PERSONS
-- canvas_x / canvas_y NULL  → unassigned (shown in sidebar panel)
-- Dates stored as VARCHAR to support partial dates:
--   YYYY  |  MM/YYYY  |  DD/MM/YYYY
-- ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `persons` (
  `id`          CHAR(36)        NOT NULL,
  `first_name`  VARCHAR(255)    DEFAULT NULL,
  `last_name`   VARCHAR(255)    DEFAULT NULL,
  `maiden_name` VARCHAR(255)    DEFAULT NULL,
  `gender`      ENUM('male','female','other') DEFAULT NULL,
  `birth_date`  VARCHAR(20)     DEFAULT NULL,
  `birth_place` VARCHAR(500)    DEFAULT NULL,
  `death_date`  VARCHAR(20)     DEFAULT NULL,
  `death_place` VARCHAR(500)    DEFAULT NULL,
  `occupation`  VARCHAR(500)    DEFAULT NULL,
  `education`   VARCHAR(500)    DEFAULT NULL,
  `eye_color`   VARCHAR(100)    DEFAULT NULL,
  `hair_color`  VARCHAR(100)    DEFAULT NULL,
  `notes`       TEXT            DEFAULT NULL,
  `canvas_x`    DOUBLE          DEFAULT NULL,
  `canvas_y`    DOUBLE          DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name`  (`last_name`, `first_name`),
  KEY `idx_birth` (`birth_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────
-- COUPLES
-- rel_type: 'married' = solid line | 'unmarried' = dashed
-- status:   'active'  | 'divorced' (red ×) | 'widowed' (black ×)
-- line_color: hex, default blue.  Each extra marriage gets a
--             different shade automatically from the frontend.
-- ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `couples` (
  `id`          CHAR(36)        NOT NULL,
  `person1_id`  CHAR(36)        NOT NULL,
  `person2_id`  CHAR(36)        NOT NULL,
  `rel_type`    ENUM('married','unmarried') NOT NULL DEFAULT 'married',
  `start_date`  VARCHAR(20)     DEFAULT NULL,
  `start_place` VARCHAR(500)    DEFAULT NULL,
  `status`      ENUM('active','divorced','widowed') NOT NULL DEFAULT 'active',
  `end_date`    VARCHAR(20)     DEFAULT NULL,
  `end_place`   VARCHAR(500)    DEFAULT NULL,
  `line_color`  VARCHAR(20)     NOT NULL DEFAULT '#2563eb',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_p1` (`person1_id`),
  KEY `idx_p2` (`person2_id`),
  CONSTRAINT `fk_couple_p1` FOREIGN KEY (`person1_id`)
    REFERENCES `persons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_couple_p2` FOREIGN KEY (`person2_id`)
    REFERENCES `persons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────
-- PARENT_CHILD
-- couple_id NOT NULL → child line drawn from that couple's
--   marriage line midpoint (perpendicular / junction style).
-- couple_id NULL     → solo parent, drawn from individual card.
-- ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `parent_child` (
  `id`          CHAR(36)        NOT NULL,
  `parent_id`   CHAR(36)        NOT NULL,
  `child_id`    CHAR(36)        NOT NULL,
  `couple_id`   CHAR(36)        DEFAULT NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parent_child` (`parent_id`, `child_id`),
  KEY `idx_parent`    (`parent_id`),
  KEY `idx_child`     (`child_id`),
  KEY `idx_pc_couple` (`couple_id`),
  CONSTRAINT `fk_pc_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `persons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pc_child` FOREIGN KEY (`child_id`)
    REFERENCES `persons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pc_couple` FOREIGN KEY (`couple_id`)
    REFERENCES `couples` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;


-- ═══════════════════════════════════════════════════════════════
-- USEFUL VIEWS  (for direct research queries / data exports)
-- ═══════════════════════════════════════════════════════════════

CREATE OR REPLACE VIEW `v_persons_full` AS
SELECT
  p.*,
  TRIM(CONCAT_WS(' ', p.first_name, p.last_name))  AS full_name,
  CASE p.gender
    WHEN 'male'   THEN 'Son'
    WHEN 'female' THEN 'Daughter'
    ELSE 'Child'
  END AS child_label
FROM `persons` p;


CREATE OR REPLACE VIEW `v_couples_detail` AS
SELECT
  c.*,
  TRIM(CONCAT_WS(' ', p1.first_name, p1.last_name)) AS person1_name,
  TRIM(CONCAT_WS(' ', p2.first_name, p2.last_name)) AS person2_name
FROM `couples` c
LEFT JOIN `persons` p1 ON p1.id = c.person1_id
LEFT JOIN `persons` p2 ON p2.id = c.person2_id;


CREATE OR REPLACE VIEW `v_family_relationships` AS
SELECT
  'couple'      AS rel_kind,
  c.id          AS rel_id,
  c.person1_id  AS from_id,
  TRIM(CONCAT_WS(' ', p1.first_name, p1.last_name)) AS from_name,
  c.person2_id  AS to_id,
  TRIM(CONCAT_WS(' ', p2.first_name, p2.last_name)) AS to_name,
  c.rel_type    AS detail,
  c.start_date  AS date1,
  c.end_date    AS date2,
  c.status
FROM `couples` c
LEFT JOIN `persons` p1 ON p1.id = c.person1_id
LEFT JOIN `persons` p2 ON p2.id = c.person2_id

UNION ALL

SELECT
  'parent_child' AS rel_kind,
  pc.id          AS rel_id,
  pc.parent_id   AS from_id,
  TRIM(CONCAT_WS(' ', p1.first_name, p1.last_name)) AS from_name,
  pc.child_id    AS to_id,
  TRIM(CONCAT_WS(' ', p2.first_name, p2.last_name)) AS to_name,
  IF(pc.couple_id IS NOT NULL, pc.couple_id, 'solo') AS detail,
  NULL           AS date1,
  NULL           AS date2,
  'active'       AS status
FROM `parent_child` pc
LEFT JOIN `persons` p1 ON p1.id = pc.parent_id
LEFT JOIN `persons` p2 ON p2.id = pc.child_id;


-- ═══════════════════════════════════════════════════════════════
-- OPTIONAL SAMPLE ROWS (comment out if starting from scratch)
-- ═══════════════════════════════════════════════════════════════
-- INSERT INTO persons (id, first_name, last_name, birth_date, gender, canvas_x, canvas_y)
-- VALUES (UUID(), 'Heinrich', 'Müller', '12/03/1922', 'male', 800, 160);
