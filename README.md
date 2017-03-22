# Lightweight Term Count Update

This plugin causes the _update_term_count_on_transition_post_status action to no longer execute the normal callback, which causes a recount of post counts for each term associated with the updating post.

Rather, it will instead perform a simple increment/decrement on the post count that does not require a recalculation.