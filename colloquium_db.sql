CREATE TABLE AppUser (
    user_id INT(20) NOT NULL,
    fname VARCHAR(50) NOT NULL,
    lname VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL,
    role ENUM('student','professor','admin') NOT NULL,
    password_hash VARCHAR(255) NULL,
    is_active TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_appuser PRIMARY KEY (user_id),
    CONSTRAINT uq_appuser_email UNIQUE (email)
);

CREATE TABLE Professor (
    professor_id INT(20) NOT NULL,
    fname VARCHAR(50) NOT NULL,
    lname VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL,
    permitted_event_types VARCHAR(500) NULL,
    CONSTRAINT pk_professor PRIMARY KEY (professor_id),
    CONSTRAINT uq_prof_email UNIQUE (email),
    CONSTRAINT fk_prof_appuser FOREIGN KEY (professor_id)
        REFERENCES AppUser(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE Student (
    student_id INT NOT NULL,
    fname VARCHAR(50) NOT NULL,
    lname VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL,
    year ENUM('Freshman','Sophomore','Junior','Senior') NOT NULL,
    CONSTRAINT pk_student PRIMARY KEY (student_id),
    CONSTRAINT uq_student_email UNIQUE (email),
    CONSTRAINT fk_stu_appuser FOREIGN KEY (student_id)
        REFERENCES AppUser(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE Course (
    course_id VARCHAR(20) NOT NULL,
    course_name VARCHAR(255) NOT NULL,
    section VARCHAR(10) NOT NULL,
    year INT NOT NULL,
    semester VARCHAR(20) NOT NULL,
    minimum_events_required INT NOT NULL DEFAULT 0,
    CONSTRAINT pk_course PRIMARY KEY (course_id),
    CONSTRAINT uq_course_instance UNIQUE (course_id, section, semester, year),
    CONSTRAINT chk_min_events CHECK (minimum_events_required >= 0)
);

CREATE TABLE CourseAssignment (
    assignment_id INT NOT NULL AUTO_INCREMENT,
    course_id VARCHAR(20) NOT NULL,
    professor_id INT NOT NULL,
    CONSTRAINT pk_course_assignment PRIMARY KEY (assignment_id),
    CONSTRAINT uq_course_prof UNIQUE (course_id, professor_id),
    CONSTRAINT fk_ca_course FOREIGN KEY (course_id)
        REFERENCES Course(course_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ca_professor FOREIGN KEY (professor_id)
        REFERENCES Professor(professor_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE EnrollmentInCourses (
    enrollment_id INT NOT NULL AUTO_INCREMENT,
    student_id INT NOT NULL,
    course_id VARCHAR(20) NOT NULL,
    status ENUM('active','dropped','withdrawn') NOT NULL DEFAULT 'active',
    CONSTRAINT pk_enrollment PRIMARY KEY (enrollment_id),
    CONSTRAINT uq_enrollment UNIQUE (student_id, course_id),
    CONSTRAINT fk_enroll_student FOREIGN KEY (student_id)
        REFERENCES Student(student_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_enroll_course FOREIGN KEY (course_id)
        REFERENCES Course(course_id)
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE Event (
    event_id INT NOT NULL AUTO_INCREMENT,
    event_name VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    location VARCHAR(255) NOT NULL,
    created_by INT NULL,
    CONSTRAINT pk_event PRIMARY KEY (event_id),
    CONSTRAINT chk_event_times CHECK (end_time > start_time),
    CONSTRAINT fk_event_creator FOREIGN KEY (created_by)
        REFERENCES AppUser(user_id)
        ON DELETE SET NULL
);

CREATE TABLE AttendsEventSessions (
    student_id INT NOT NULL,
    event_id INT NOT NULL,
    start_scan_time DATETIME NULL,
    end_scan_time DATETIME NULL,
    minutes_present INT GENERATED ALWAYS AS (TIMESTAMPDIFF(MINUTE, start_scan_time, end_scan_time)) STORED,
    audit_note TEXT NULL,
    overridden_by INT NULL,
    CONSTRAINT pk_attendance PRIMARY KEY (student_id, event_id),
    CONSTRAINT fk_att_student FOREIGN KEY (student_id)
        REFERENCES Student(student_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_att_event FOREIGN KEY (event_id)
        REFERENCES Event(event_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_att_override FOREIGN KEY (overridden_by)
        REFERENCES AppUser(user_id)
        ON DELETE SET NULL,
    CONSTRAINT chk_scan_order CHECK (
        end_scan_time IS NULL OR start_scan_time IS NULL OR end_scan_time > start_scan_time
    )
);

SET FOREIGN_KEY_CHECKS = 1;