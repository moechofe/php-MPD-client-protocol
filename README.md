Music Player Daemon Client Protocol
===================================

This is not a complete API to manipulate data from a MPD server, but only a simple helper to send commands, retreive data and manage error correctly.

For example:
The function Mpd::setvol() don't check if you use the right type for the only one argument.
It only send the "setvol" command and throw an exeception if the server return an error message.

That's mean, you need to develop your own client using the [MPD documentation].

[MPD documentation:http://www.musicpd.org/doc/protocol/index.html]
