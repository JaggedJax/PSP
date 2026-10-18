<?php

namespace PSP\Files;

use PSP\Log\Log;

/**
 * Thin wrapper around PHP's native ftp_* extension for uploading a local file to a remote FTP/FTPS
 * server. Mirrors the Http helper's shape: construct once with connection details, call
 * upload_file() to send data, throws FtpException on any failure. Uploads stream directly from the
 * local file handle (not through an in-memory string), so callers building large exports never
 * need to hold the whole file in memory to send it.
 */
class Ftp implements RemoteFileClient
{
	private string $host;
	private int $port;
	private bool $ssl;
	private bool $passive;
	private $connection = null;

	/**
	 * @param string $host
	 * @param int $port
	 * @param bool $ssl Connect using FTPS (ftp_ssl_connect) instead of plain FTP
	 * @param bool $passive Use passive mode transfers
	 */
	public function __construct(string $host, int $port = 21, bool $ssl = false, bool $passive = true)
	{
		$this->host = $host;
		$this->port = $port;
		$this->ssl = $ssl;
		$this->passive = $passive;
	}

	/**
	 * Connect and authenticate. Must be called before upload_file()
	 * @param string $username
	 * @param string $password
	 * @throws FtpException
	 */
	public function login(string $username, string $password): void
	{
		if (!function_exists('ftp_connect')) {
			throw new FtpException('FTP needs the PHP ftp extension');
		}
		$connection = $this->ssl
			? @ftp_ssl_connect($this->host, $this->port)
			: @ftp_connect($this->host, $this->port);

		if ($connection === false) {
			throw new FtpException("Unable to connect to FTP host {$this->host}:{$this->port}");
		}

		if (!@ftp_login($connection, $username, $password)) {
			ftp_close($connection);
			throw new FtpException("FTP login failed for user $username on {$this->host}:{$this->port}");
		}

		if (!@ftp_pasv($connection, $this->passive)) {
			Log::warning("Failed to set FTP passive mode to " . ($this->passive ? 'on' : 'off') . " for {$this->host}:{$this->port}");
		}

		$this->connection = $connection;
	}

	/**
	 * Upload a local file to a remote path, streaming directly from the local file handle so the
	 * whole file never needs to fit in memory as a single string
	 * @param string $local_path Path to an existing local file to read from
	 * @param string $remote_path Full remote path, including filename
	 * @throws FtpException
	 */
	public function upload_file(string $local_path, string $remote_path): void
	{
		if (empty($this->connection)) {
			throw new FtpException("upload_file() called before login()");
		}

		$stream = @fopen($local_path, 'r');
		if ($stream === false) {
			throw new FtpException("Unable to open local file for upload: $local_path");
		}

		$success = @ftp_fput($this->connection, $remote_path, $stream, FTP_BINARY);
		fclose($stream);

		if (!$success) {
			throw new FtpException("Failed to upload $remote_path to {$this->host}");
		}
	}

	/**
	 * List filenames (not full paths) at a remote directory matching a glob-style pattern - used to
	 * discover inbound files (eg. shipment/inventory exports a WMS drops for us to pick up)
	 * @param string $path Remote directory to list
	 * @param string $pattern glob-style pattern (eg. "ship_*.csv"), matched with fnmatch() against
	 *  each entry's basename
	 * @return string[] Matching filenames, basename only
	 * @throws FtpException
	 */
	public function list_files(string $path, string $pattern = '*'): array
	{
		if (empty($this->connection)) {
			throw new FtpException("list_files() called before login()");
		}

		$entries = @ftp_nlist($this->connection, $path);
		if ($entries === false) {
			// A missing/empty directory isn't a connection failure - nothing to retrieve
			return [];
		}

		$matches = [];
		foreach ($entries as $entry) {
			$basename = basename($entry);
			if (fnmatch($pattern, $basename)) {
				$matches[] = $basename;
			}
		}
		return $matches;
	}

	/**
	 * Download a remote file to a local path, streaming directly into the local file handle so the
	 * whole file never needs to fit in memory as a single string - mirrors upload_file()'s streaming
	 * in the opposite direction
	 * @param string $remote_path Full remote path, including filename
	 * @param string $local_path Local path to write to (overwritten if it already exists)
	 * @throws FtpException
	 */
	public function download_file(string $remote_path, string $local_path): void
	{
		if (empty($this->connection)) {
			throw new FtpException("download_file() called before login()");
		}

		$stream = @fopen($local_path, 'w');
		if ($stream === false) {
			throw new FtpException("Unable to open local file for download: $local_path");
		}

		$success = @ftp_fget($this->connection, $stream, $remote_path, FTP_BINARY);
		fclose($stream);

		if (!$success) {
			throw new FtpException("Failed to download $remote_path from {$this->host}");
		}
	}

	/**
	 * Move/rename a remote file, creating the target's parent directory first if it doesn't exist
	 * yet - used to archive an inbound file into a processed/ subfolder after it's been consumed, so
	 * the same file isn't picked up again by the next run. Best-effort: returns false rather than
	 * throwing, since a failure here should never be treated the same as a real connection/transfer
	 * error by callers (the file was already successfully processed)
	 * @param string $from Full remote source path
	 * @param string $to Full remote destination path
	 * @return bool Success
	 */
	public function move_file(string $from, string $to): bool
	{
		if (empty($this->connection)) {
			return false;
		}

		if (@ftp_rename($this->connection, $from, $to)) {
			return true;
		}

		// Most likely cause of a first failure: the destination directory doesn't exist yet
		@ftp_mkdir($this->connection, dirname($to));

		return (bool)@ftp_rename($this->connection, $from, $to);
	}

	/**
	 * Check whether a remote directory exists and is accessible, without changing the current
	 * working directory or leaving any file behind - best-effort, relying on ftp_nlist() returning
	 * false for a missing/inaccessible path (standard FTP server behavior, though not guaranteed
	 * universally)
	 * @param string $path
	 * @return bool
	 * @throws FtpException
	 */
	public function path_exists(string $path): bool
	{
		if (empty($this->connection)) {
			throw new FtpException("path_exists() called before login()");
		}

		return @ftp_nlist($this->connection, $path) !== false;
	}

	/**
	 * Does a remote file exist? Uses SIZE, and the directory listing when the server does not support SIZE
	 * @param string $remote_path Full remote path, including filename
	 * @return bool
	 * @throws FtpException
	 */
	public function file_exists(string $remote_path): bool
	{
		if (empty($this->connection)) {
			throw new FtpException("file_exists() called before login()");
		}

		if (@ftp_size($this->connection, $remote_path) >= 0) {
			return true;
		}
		$entries = @ftp_nlist($this->connection, dirname($remote_path));
		if ($entries === false) {
			return false;
		}
		$name = basename($remote_path);
		foreach ($entries as $entry) {
			if (basename($entry) === $name) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete a remote file. Best-effort
	 * @param string $remote_path
	 * @return bool Success
	 */
	public function delete_file(string $remote_path): bool
	{
		return !empty($this->connection) && @ftp_delete($this->connection, $remote_path);
	}

	/**
	 * Rename a remote file in the same directory. Unlike move_file(), it creates no directory
	 * @param string $from
	 * @param string $to
	 * @return bool Success
	 */
	public function rename_file(string $from, string $to): bool
	{
		return !empty($this->connection) && @ftp_rename($this->connection, $from, $to);
	}

	/**
	 * Close the connection. Safe to call even if login() was never called
	 */
	public function close(): void
	{
		if (!empty($this->connection)) {
			ftp_close($this->connection);
			$this->connection = null;
		}
	}

	public function __destruct()
	{
		$this->close();
	}
}
