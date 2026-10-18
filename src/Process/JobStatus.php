<?php

namespace PSP\Process;

enum JobStatus: string
{
	case Waiting = 'W';
	case Queued = 'Q';
	case Running = 'R';
	case Complete = 'C';
	case TimedOut = 'T';
}
