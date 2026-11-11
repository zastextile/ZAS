-- ZAS Attendance — admin screens.
--
-- An employee code is somebody else's number. The production module already
-- learned this the hard way and widened zp_workers.worker_code to 40
-- characters; the attendance roll was created with 32 for its own code and 20
-- for the link back to a production worker. Bringing production workers onto
-- the attendance roll would then either refuse the long ones or — outside
-- strict mode — cut them silently and make two people one code.
--
-- Widening a VARCHAR loses nothing and changes no existing value.

ALTER TABLE att_employees
    MODIFY code        VARCHAR(40) NOT NULL,
    MODIFY worker_code VARCHAR(40) NULL;
