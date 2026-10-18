<?php

namespace PSP\Files;

/**
 * File operations on a remote server after login, the same for FTP (Ftp) and SFTP (Sftp), so the
 * file transports share one implementation. Transfers stream from and to local files
 */
interface RemoteFileClient
{
	/**
	 * @param string $local_path
	 * @param string $remote_path Full remote path, including file name
	 * @throws RemoteFileException
	 */
	public function upload_file(string $local_path, string $remote_path): void;

	/**
	 * @param string $path Remote directory
	 * @param string $pattern glob-style pattern matched against each file name
	 * @return string[] Matching file names, without the directory. Empty when the directory does not exist
	 * @throws RemoteFileException
	 */
	public function list_files(string $path, string $pattern = '*'): array;

	/**
	 * @param string $remote_path
	 * @param string $local_path Overwritten if it exists
	 * @throws RemoteFileException
	 */
	public function download_file(string $remote_path, string $local_path): void;

	/**
	 * Move a file, creating the target directory when it does not exist. Best-effort
	 * @param string $from
	 * @param string $to
	 * @return bool Success
	 */
	public function move_file(string $from, string $to): bool;

	/**
	 * Rename a file in the same directory. Creates no directory
	 * @param string $from
	 * @param string $to
	 * @return bool Success
	 */
	public function rename_file(string $from, string $to): bool;

	/**
	 * @param string $remote_path
	 * @return bool Success
	 */
	public function delete_file(string $remote_path): bool;

	/**
	 * @param string $remote_path
	 * @return bool
	 * @throws RemoteFileException
	 */
	public function file_exists(string $remote_path): bool;

	/**
	 * Does a remote directory exist and can it be read?
	 * @param string $path
	 * @return bool
	 * @throws RemoteFileException
	 */
	public function path_exists(string $path): bool;

	/**
	 * Safe to call more than once
	 */
	public function close(): void;
}
