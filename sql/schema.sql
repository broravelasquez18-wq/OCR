-- =====================================================================
-- Verificador de documentacion por OCR - esquema
-- MySQL 8 / InnoDB / utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Usuarios (auth simple, un solo operador por ahora; listo para roles)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(150) NOT NULL,
  email         VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol           VARCHAR(30)  NOT NULL DEFAULT 'operador',
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Lotes de verificacion
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lotes_verificacion (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_ficha   VARCHAR(20)  NOT NULL,
  programa       VARCHAR(255) NULL,
  origen_lista   VARCHAR(255) NULL,
  estado         ENUM('en_proceso','verificado','cerrado') NOT NULL DEFAULT 'en_proceso',
  creado_por     INT UNSIGNED NULL,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ficha (codigo_ficha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Inscritos (lista importada del .xls del SENA)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inscritos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lote_id          INT UNSIGNED NOT NULL,
  tipo_documento   VARCHAR(5)   NOT NULL DEFAULT 'CC',
  numero_documento VARCHAR(20)  NOT NULL,     -- normalizado: solo digitos
  nombre           VARCHAR(150) NOT NULL,
  estado_sena      VARCHAR(30)  NULL,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inscrito_lote FOREIGN KEY (lote_id)
    REFERENCES lotes_verificacion(id) ON DELETE CASCADE,
  UNIQUE KEY uq_lote_documento (lote_id, numero_documento),
  INDEX idx_documento (numero_documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Documentos leidos por OCR
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos_ocr (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lote_id      INT UNSIGNED NOT NULL,
  origen       ENUM('pdf','foto','camara') NOT NULL,
  ruta_imagen  VARCHAR(255) NOT NULL,
  ruta_reverso VARCHAR(255) NULL,
  pagina_reverso INT NULL,
  pagina       INT NULL,
  numero_leido VARCHAR(20) NULL,
  nombres      VARCHAR(150) NULL,
  apellidos    VARCHAR(150) NULL,
  nombre_completo VARCHAR(150) NULL,
  zona_fuente  ENUM('mrz','codigo_barras','frontal','desconocida') NULL,
  confianza    DECIMAL(5,2) NULL,
  fecha_nacimiento DATE NULL,
  fecha_zona   ENUM('mrz','frontal') NULL,
  texto_crudo  TEXT NULL,
  procesado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_lote FOREIGN KEY (lote_id)
    REFERENCES lotes_verificacion(id) ON DELETE CASCADE,
  INDEX idx_numero_leido (numero_leido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Verificaciones (una fila por inscrito; el cruce)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS verificaciones (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lote_id           INT UNSIGNED NOT NULL,
  inscrito_id       INT UNSIGNED NOT NULL,
  documento_ocr_id  INT UNSIGNED NULL,
  numero_confirmado VARCHAR(20) NULL,
  nombres_manual    VARCHAR(150) NULL,
  apellidos_manual  VARCHAR(150) NULL,
  fecha_nacimiento  DATE NULL,               -- fecha confirmada/corregida por el operador
  fecha_manual      TINYINT(1) NOT NULL DEFAULT 0,
  estado            ENUM('ok','corregido','no_coincide','falta_documento') NOT NULL,
  corregido_manual  TINYINT(1) NOT NULL DEFAULT 0,
  observacion       VARCHAR(255) NULL,
  confirmado        TINYINT(1) NOT NULL DEFAULT 0,
  verificado_por    INT UNSIGNED NULL,
  verificado_en     DATETIME NULL,
  CONSTRAINT fk_ver_lote     FOREIGN KEY (lote_id)          REFERENCES lotes_verificacion(id) ON DELETE CASCADE,
  CONSTRAINT fk_ver_inscrito FOREIGN KEY (inscrito_id)      REFERENCES inscritos(id)          ON DELETE CASCADE,
  CONSTRAINT fk_ver_doc      FOREIGN KEY (documento_ocr_id) REFERENCES documentos_ocr(id)     ON DELETE SET NULL,
  UNIQUE KEY uq_inscrito (inscrito_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS autocompletados (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lote_id INT UNSIGNED NOT NULL,
  destino VARCHAR(20) NOT NULL,
  destino_id INT UNSIGNED NOT NULL,
  origen VARCHAR(20) NOT NULL,
  origen_id INT UNSIGNED NOT NULL,
  cambios TEXT NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auto_lote (lote_id),
  CONSTRAINT fk_auto_lote FOREIGN KEY (lote_id) REFERENCES lotes_verificacion(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Semilla: operador por defecto (password: "cambiar123" - CAMBIAR EN PROD)
-- El hash corresponde a password_hash('cambiar123', PASSWORD_DEFAULT).
-- Puedes regenerarlo con: php -r "echo password_hash('tu_clave', PASSWORD_DEFAULT);"
-- ---------------------------------------------------------------------
INSERT INTO usuarios (nombre, email, password_hash, rol)
SELECT 'Operador', 'operador@local', '$2y$10$/T5NycI/VXO2m8lHL7mwk.GgHRl4worWiZwpTAeYgq4oMyqhf7gM.', 'operador'
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE email = 'operador@local');
