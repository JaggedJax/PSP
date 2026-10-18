CREATE PROCEDURE `job_update_schedule`(IN job_id_in INT, IN schedule_id_in INT)
BEGIN
    SET @job_id := job_id_in;
    SET @schedule_id := schedule_id_in;
    SET @bucket_type := NULL;
    SET @num_buckets := NULL;
    SET @num_sub_buckets := NULL;
    SET @bucketjoin := "";
    SET @subbucketjoin := "";
    SET @x := 2;
    SET @y := 2;
    SET @qry := '';
    SET @new_bucket := NULL;
    SET @new_sub_bucket := NULL;

    # Get bucket_type and num_buckets for given schedule
    SELECT bucket_type, CASE bucket_type WHEN 'minute' THEN substring_index(minute, '/', -1)
                                         WHEN 'hour' THEN (CASE WHEN LOCATE('/', hour) > 0 THEN substring_index(hour, '/', -1) ELSE 1 END)
                                         ELSE NULL
        END as num_buckets INTO @bucket_type, @num_buckets
    FROM job_schedules
    WHERE job_schedule_id=@schedule_id LIMIT 1;

    # Prep the join that will be used to calculate least used bucket
    IF @bucket_type IS NOT NULL AND @num_buckets IS NOT NULL THEN
        SET @bucketjoin := CONCAT("SELECT 1 as bucket, ",@schedule_id," as job_schedule_id");
        WHILE @x <= @num_buckets DO
                SET @bucketjoin := CONCAT(@bucketjoin," UNION SELECT ", @x, ",", @schedule_id);
                SET @x = @x+1;
            END WHILE;
    END IF;

    IF @bucket_type = 'minute' THEN
        # Calculate least used bucket for 'minute' type
        SET @qry := CONCAT("SELECT j.bucket, COUNT(jobs.job_id) as count INTO @new_bucket, @x FROM jobs
			RIGHT JOIN (", @bucketjoin, ") j USING (bucket, job_schedule_id)
			WHERE (jobs.job_schedule_id = ",@schedule_id," OR jobs.job_schedule_id <> ",@schedule_id," OR jobs.job_schedule_id IS NULL)
			GROUP BY j.bucket ORDER BY count ASC LIMIT 1");
        PREPARE stmt FROM @qry;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SET @new_sub_bucket := NULL;
    ELSEIF @bucket_type = 'hour' THEN
        # Calculate least used bucket for 'hour' type
        SET @qry := CONCAT("SELECT j.bucket, COUNT(jobs.job_id) as count INTO @new_bucket, @x FROM jobs
			RIGHT JOIN (", @bucketjoin, ") j USING (bucket, job_schedule_id)
			WHERE (jobs.job_schedule_id = ",@schedule_id," OR jobs.job_schedule_id <> ",@schedule_id," OR jobs.job_schedule_id IS NULL)
			GROUP BY j.bucket ORDER BY count ASC LIMIT 1");
        PREPARE stmt FROM @qry;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;

        # Prep the join that will be used to calculate least used sub_bucket
        SET @num_sub_buckets := 60;
        SET @subbucketjoin := CONCAT("SELECT 1 as sub_bucket, ",@schedule_id," as job_schedule_id");
        WHILE @y <= @num_sub_buckets DO
                SET @subbucketjoin := CONCAT(@subbucketjoin," UNION SELECT ", @y, ",", @schedule_id);
                SET @y = @y+1;
            END WHILE;

        # Calculate least used sub_bucket for 'hour' type
        SET @qry := CONCAT("SELECT j.sub_bucket, COUNT(jobs.job_id) as count INTO @new_sub_bucket, @x FROM jobs
			RIGHT JOIN (", @subbucketjoin, ") j USING (sub_bucket, job_schedule_id)
			WHERE (jobs.job_schedule_id=", @schedule_id, " OR jobs.job_schedule_id <> ",@schedule_id," OR jobs.job_schedule_id IS NULL) AND (jobs.bucket=", @new_bucket, " OR jobs.bucket=", @new_bucket, " OR jobs.bucket IS NULL)
			GROUP BY j.sub_bucket ORDER BY count ASC LIMIT 1");
        PREPARE stmt FROM @qry;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;

    # Update requested job with requested schedule, setting the bucket and sub_bucket values
    UPDATE jobs SET job_schedule_id=@schedule_id, bucket=@new_bucket, sub_bucket=@new_sub_bucket WHERE job_id=@job_id LIMIT 1;
END