Пример Drupal модуля (написан на symfony)

Разработано для авторского интернет-магазина агрегатора предложений (партнерская сеть). 

Бэкграунд по задаче: 
 - Есть база данных партнеров с указанием их активности (да/нет) и ссылками на XML файл с выгрузкой предложений и остатков товаров с привязкой к SKU и региону
 - XML файлы чем то напоминают выгрузку яндекс товаров.
 - Обеспечивается синхронизация статуса предложений партнеров, цен и остатков раз в час
 - Ошибки сихронизации записываются в БД с привязкой к партнеру для отображения удобного списка статусов выгрузки (сам список не часть этого репозитория)
 - Партнеры, у которых отсутствует заявленный XML файл диактивируются до ручного разбирательства.
 - Отправляются письма в случае важных событий таких как: отключение партнера, изменение количества представленных у партнеров регионов представительства, изменения количества представленных у партнеров товаров.