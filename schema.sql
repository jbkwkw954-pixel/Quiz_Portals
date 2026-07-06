CREATE DATABASE IF NOT EXISTS exam_portal CHARACTER SET utf8mb4;
USE exam_portal;

CREATE TABLE IF NOT EXISTS exam_sessions (
    id VARCHAR(36) PRIMARY KEY,               -- UUID, generated at registration
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    course VARCHAR(50) NOT NULL,
    answers_json TEXT NULL,                   -- {questionIndex: chosenOptionIndex}
    score_correct INT NULL,
    score_total INT NULL,
    status ENUM('in_progress','passed','failed','malpractice') NOT NULL DEFAULT 'in_progress',
    lock_reason VARCHAR(255) NULL,
    feedback_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS violation_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(36) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES exam_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
