# Troubleshooting

This document answers the question: "I have everything in stalled and my server says "can be reached" but my index isn't returning any documents when I search. What do I do?"

Troubleshooting your search_api_pantheon search results:

1. Verify a server round trip is working correctly.

   Using terminus run the drush command "search-api-pantheon:diagnose".

   ```bash

   terminus drush {site_name}.{env} -- search-api-pantheon:diagnose

   ```

   This command diagnoses problems with connections and can sniff out where the problem exists by what part of the command fails or succeeds. If the command completes without error, the connection to the server is working and you're ready to index.

2. Do you have, or did you create an index?

   ![Don't Do!](images/screen_shot_index.png)

   In order to return search results, your search server has to have at least one "index". Your index must index content from at least one datasource (ideally, "content") and you must choose "pantheon"'s driver as the server for that index.

3. If you have special permissions on your content, make sure the index is created with the correct user context.

   ![Don't Do!](images/screen_shot_1.png)

   We reccommend that your search results index "FULL CONTENT" views of all content types.

   ![Do This!](images/screen_shot_2.png)

4. Once you have established the index, you will need to add fields. We recommend that unless you know what you're doing, add the "rendered content" field and the "uri" field.

   ![Do This!](images/screen_shot_add_fields.png)

   ![Do This!](images/screen_shot_fields_1.png)

   ![Do This!](images/screen_shot_fields_2.png)

5. Anytime you make a change in the server's fields, you will need to re-send all of your content from the site to the Solr index for indexing.

   ![Do This!](images/screen_shot_index_content.png)

   ![Do This!](images/screen_shot_index_complete.png)


