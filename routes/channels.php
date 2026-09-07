<?php

/*
| The wall has no logged-in user — it is gated by a display token, not a
| session — so a private channel would need an auth endpoint that understood
| that token. It does not need one: the only thing broadcast is a nudge saying
| "Home Assistant changed something", carrying no payload at all. Everything
| worth protecting is still behind the token on the page itself.
*/
