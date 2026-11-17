@applying_taxes
Feature: Apply taxes defined for a zone consisting of other zones
    In order to pay correct taxes when the tax rate is defined for a zone consisting of other zones
    As a Customer
    I want the tax rate of the zone containing my zone to be applied when my zone has no tax rate of its own

    Background:
        Given the store operates on a single channel
        And the store operates in "Spain" and "Lithuania"
        And the store has a zone "Europe" with code "EUROPE"
        And it has the "Spain" country member
        And the store also has a zone "Lithuania" with code "LITHUANIA"
        And it has the "Lithuania" country member
        And the store has a tax zone "All zones" with code "ALL_ZONES"
        And it has the zone named "Europe"
        And it also has the zone named "Lithuania"
        And the store has "VAT" tax rate of 21% for "Clothes" within the "All zones" zone
        And the store has a product "T-Shirt" priced at "$100.00"
        And it belongs to "Clothes" tax category
        And I am a logged in customer

    @api @ui
    Scenario: Applying the tax rate of a zone consisting of zones when the matched zone has no tax rate
        Given I added product "T-Shirt" to the cart
        And I addressed the cart to "Spain"
        When I check the details of my cart
        Then my cart total should be "$121.00"
        And my cart taxes should be "$21.00"

    @api @ui
    Scenario: Applying the tax rate of the matched zone instead of the one of the zone consisting of zones
        Given the store has "Reduced VAT" tax rate of 5% for "Clothes" within the "Lithuania" zone
        And I added product "T-Shirt" to the cart
        And I addressed the cart to "Lithuania"
        When I check the details of my cart
        Then my cart total should be "$105.00"
        And my cart taxes should be "$5.00"
