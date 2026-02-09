@mod @mod_adele @javascript @mod_adele_use

Feature: As an admin I create adele activity and view it as a student.

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname  | email                       |
      | student1  | Student   | 1         | toolgenerator1@example.com  |
      | student2  | Student   | 2         | toolgenerator2@example.com  |
      | teacher   | Teacher   | Test      | toolgenerator3@example.com  |
      | manager   | Manager   | Test      | toolgenerator4@example.com  |
    And the following "courses" exist:
      | fullname           | shortname | summary     |
      | Course 1           | C1        | LP Course 1 |
      | Course 2           | C2        | LP Course 2 |
      | Course 3           | C3        | LP Course 3 |
      | Course 4           | C4        | LP Course 4 |
      | LP Activity Course | LPAC      | LP Activity Course |
    And the following "course enrolments" exist:
      | user     | course   | role           |
      | student1 | LPAC     | student        |
      | student2 | LPAC     | student        |
      | teacher  | LPAC     | editingteacher |
    And the following config values are set as admin:
      | config            | value                                                      | plugin      |
      | restrictionfilter | timed,timed_duration,specific_course,parent_courses,manual | local_adele |
    And the following "local_adele > learningpaths" exist:
      | name     | description   | filepath                                               | courses     | image                                                 |
      | Test LP1 | Test LP1 Desc | local/adele/tests/fixtures/learning_plan3_courses.json | C1,C2,C3,C4 | /local/adele/public/node_background_image/image_1.jpg |
    And I change viewport size to "1366x3000"

  @javascript
  Scenario: Adele activity: add instance, assign learning plan and view as student
    Given I log in as "admin"
    And I am on "LPAC" course homepage with editing mode on
    And I open the activity chooser
    When I click on "Learning path" "link" in the "Add an activity or resource" "dialogue"
    Then I should see "LP Activity Course"
    And I set the field "Learning path" to "Test LP Activity"
    And I set the field "Chosen Learning Path" to "Learning path 2025"
    And I set the field "Choose an option for how people get subscribed to the learning path" to "Everyone who is subscribed to that course"
    And I press "Save and return to course"
    Then I should see "Test LP Activity" in the "New section" "section"
    And I should see "Learning Path 2025"
    And I should see "Course 1" in the ".vue-flow__pane.vue-flow__container.draggable" "css_element"
    And I should see "Course 2" in the ".vue-flow__pane.vue-flow__container.draggable" "css_element"
    And I should see "Course 3" in the ".vue-flow__pane.vue-flow__container.draggable" "css_element"
    And I should see "Hide  User List"
    ##And I wait "31" seconds
    