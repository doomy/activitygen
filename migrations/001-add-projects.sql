CREATE TABLE t_project (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL UNIQUE
);

INSERT INTO t_project (id, name) VALUES (1, 'General');

ALTER TABLE t_activity ADD COLUMN project_id INT NOT NULL DEFAULT 1;

ALTER TABLE t_activity DROP INDEX activity;
ALTER TABLE t_activity ADD UNIQUE KEY activity_project (activity, project_id);

ALTER TABLE t_activity ADD CONSTRAINT fk_activity_project FOREIGN KEY (project_id) REFERENCES t_project(id);
