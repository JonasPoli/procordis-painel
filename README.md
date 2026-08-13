to add npm packages run:

``` php bin/console importmap:require bootstrap ``` (bootstrap example)

to install packages:

``` php bin/console importmap:install ```

to create templated CRUDs:

``` php bin/console make:custom-crud ```

if using **Runcloud** server remove `highlight_file` and `ignore_user_abort`  in 'disable_functions' at the PHP options

## Local instalation
```composer install```

Create a ```.env.local``` file with database and env enviroument:
``` 
###> symfony/framework-bundle ###
APP_ENV=dev

###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
DATABASE_URL="mysql://root:wab12345678@127.0.0.1:3306/procordis-associados?serverVersion=8.0&charset=utf8mb4"
###< doctrine/doctrine-bundle ###
```
### Create Database
```symfony console doctrine:database:create```



### Migrations
```symfony console doctrine:migrations:migrate```


### Create a Uer Admin
```symfony console app:admin-user```


## Compile o tailwind
```php bin/console tailwind:build```

### Run server
```symfony serve```