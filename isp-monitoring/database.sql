-- Aplikasi Monitoring Gangguan ISP
-- Skema database untuk MySQL / MariaDB (XAMPP)

CREATE DATABASE IF NOT EXISTS isp_monitoring CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE isp_monitoring;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  name VARCHAR(100) NOT NULL,
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  ip_address VARCHAR(200) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  monitor_mode VARCHAR(20) NOT NULL DEFAULT 'ping',
  is_online TINYINT(1) DEFAULT 1,
  ping_response_ms INT DEFAULT NULL,
  last_ping DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vendors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(100) DEFAULT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  email VARCHAR(100) DEFAULT NULL,
  description VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subject VARCHAR(200) NOT NULL,
  description TEXT,
  vendor_id INT DEFAULT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_by INT DEFAULT NULL,
  closed_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME DEFAULT NULL,
  CONSTRAINT fk_tickets_created_by FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_tickets_closed_by  FOREIGN KEY (closed_by)  REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  client_id INT DEFAULT NULL,
  client_name VARCHAR(150) DEFAULT NULL,
  ip_address VARCHAR(200) DEFAULT NULL,
  monitor_mode VARCHAR(20) NOT NULL DEFAULT 'ping',
  downtime_start DATETIME NOT NULL,
  downtime_end DATETIME DEFAULT NULL,
  status ENUM('affected','recovered') NOT NULL DEFAULT 'affected',
  closed_by INT DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  CONSTRAINT fk_tc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
  CONSTRAINT fk_tc_closed_by FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  message TEXT NOT NULL,
  attachment VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tu_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_tu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ping_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  client_id INT DEFAULT NULL,
  is_online TINYINT(1) DEFAULT 1,
  response_ms INT DEFAULT NULL,
  checked_at DATETIME NOT NULL,
  CONSTRAINT fk_pl_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Penyesuaian untuk database yang sudah ada (idempotent)
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS vendor_id INT DEFAULT NULL;
ALTER TABLE clients MODIFY COLUMN ip_address VARCHAR(200) NOT NULL;
ALTER TABLE clients ADD COLUMN IF NOT EXISTS monitor_mode VARCHAR(20) NOT NULL DEFAULT 'ping';
ALTER TABLE ticket_clients ADD COLUMN IF NOT EXISTS client_name VARCHAR(150) DEFAULT NULL;
ALTER TABLE ticket_clients ADD COLUMN IF NOT EXISTS ip_address VARCHAR(200) DEFAULT NULL;
ALTER TABLE ticket_clients ADD COLUMN IF NOT EXISTS monitor_mode VARCHAR(20) NOT NULL DEFAULT 'ping';
ALTER TABLE ticket_updates ADD COLUMN IF NOT EXISTS attachment VARCHAR(255) DEFAULT NULL;
UPDATE ticket_clients tc JOIN clients c ON c.id = tc.client_id
   SET tc.client_name = c.name, tc.ip_address = c.ip_address
   WHERE tc.client_name IS NULL;
UPDATE clients SET monitor_mode = 'noping'
   WHERE (ip_address IS NULL OR ip_address = '') AND monitor_mode <> 'noping';