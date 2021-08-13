graph TD
  A[Solr Server] -->
  B[Index] --> C{Drupal Module}
  C -->|views| D[Search Form]
  C -->|views| E[Search Page]
  C -->|views| F[Robots]
  G[Drupal Admin Module] --> |Post Schema| A
  H[Drupal Entities] --> C --> |indexed fields| B
  A --> |Status / Health| G
