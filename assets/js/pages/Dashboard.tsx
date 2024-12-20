import {SkeletonPage, Layout, SkeletonBodyText, TextContainer, SkeletonDisplayText, Page, Badge, LegacyCard, Card, BlockStack, InlineGrid, Text, Button, InlineStack, ButtonGroup, DataTable, TextField, Select} from '@shopify/polaris';
import {ConnectIcon} from '@shopify/polaris-icons';
import React, {useState, useEffect} from 'react';
import { useNavigate } from 'react-router-dom';
import Cookies from 'js-cookie';
import { createApp } from '@shopify/app-bridge';
import { Redirect } from '@shopify/app-bridge/actions';

interface AppProviderProps {
    children: React.ReactNode;
}

type SelectOption = {
    label: string;
    value: string;
}

interface SlackChannel {
    id: string;
    name: string;
    is_channel: boolean;
}

interface Notification {
    id: string;
    message: string;
    channel: SelectOption;
}

// Define types for the data state
interface DataState {
    is_loading: boolean;
    shop: string;
    access_key: string;
    code: string;
    slack_name: string;
    slack_channels: Array<SlackChannel>;
    slack_channel_options: Array<SelectOption>;
    slack_notifications: Array<Notification>;
    load_shopify_admin: boolean;
};

type NotificationOption = {
  id: string;
  name: string;
  description: string;
};

const notificationOptions: NotificationOption[] = [
    {
      id: 'orderUpdates',
      name: 'Order updates',
      description: 'Receive notifications for new and updated orders.',
    },
    {
      id: 'lowStockAlerts',
      name: 'Low stock alerts',
      description: 'Get notified when product stock is running low.',
    },
    {
      id: 'outOfStockAlerts',
      name: 'Out of stock alerts',
      description: 'Be alerted when products go out of stock.',
    },
    {
      id: 'dailySummary',
      name: 'Daily summary reports',
      description: 'Receive a daily summary of orders and stock status.',
    },
];

const Dashboard: React.FC = () => {
    const [data, setData] = useState<DataState>({
        is_loading: true,
        shop: '',
        access_key: '',
        code: '',
        slack_name: '',
        slack_channels: [],
        slack_channel_options: [],
        slack_notifications: [],
        load_shopify_admin: false
    });
    const [timeoutId, setTimeoutId] = useState<ReturnType<typeof setTimeout> | null>(null);
    const navigate = useNavigate();

    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search);
        const shop = urlParams.get('shop') || '';
        const is_loading = ( shop != '' ? true : false );
        const access_key: string = (urlParams.get('access_key') ?? (shop ? Cookies.get(`access_key_${shop}`) || '' : ''));
        const code = urlParams.get('code') || '';

        //Reset the cookie if not blank
        if( access_key != '' ) {
            Cookies.set(`access_key_${shop}`, access_key, {
                secure: true,          // Only over HTTPS
                sameSite: 'None',      // Supports cross-origin iframe contexts
                path: '/',             // Available site-wide
            });
        }

        if( access_key == '' ) {
            if( data.shop == '' ) navigate(`/`);
            else navigate(`/?shop=${encodeURIComponent(data.shop)}`);
        }

        // Set state with extracted values
        setData( (prevData) => ({
            ...prevData,
            is_loading : is_loading,
            shop : shop,
            access_key : access_key,
            code: code
        }) );
    }, []);

    useEffect(() => {
        console.log( data.load_shopify_admin + ' - ' + ( window.self === window.top ? 'load' : 'donot' ) );
        if( data.code != '' ) {
            handleCompleteConnectSlack();
        } else if( data.access_key != '' && data.is_loading ) {
            handleFetchConfig();
        } else if( data.load_shopify_admin && window.self === window.top ) {
            handleLoadInsideShopify();
        } else if( new URLSearchParams(location.search).get('host') != undefined ) {
            /*const app = createApp({
                apiKey: App.shopify_api_key,
                host: new URLSearchParams(window.location.search).get('host') ?? '',
            });

            console.log('Shopify App Bridge initialized', app);*/
        }
    }, [data]);

    const handleLoadInsideShopify = async (): Promise<void> => {
        const redirectUrl = `https://${data.shop}/admin/apps/${App.shopify_api_key}/dashboard?access_key=${data.access_key}`;
        /*
        ** Temporary off
        window.location.href = redirectUrl;
        */
    };

    const handleInitSlack = async (): Promise<void> => {
        // Add your installation logic here
        fetch(App.api_base + '/init_slack_connect', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Vuedoo-Domain': App.domain,
                'X-Vuedoo-Access-Key': data.access_key
            },
            body: JSON.stringify({})
        })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json(); // Parse JSON response
        })
        .then((res) => {
            if (res.status === 'success') {
                const redirectUrl: string = res.url + '&redirect_uri=' + encodeURIComponent( App.base + '/dashboard/?shop=' + data.shop );
                if( window.top != null ) window.top.location.href = redirectUrl;
                else window.location.href = redirectUrl;
            }
        })
        .catch((error) => {
            console.error('Error:', error);
        });
    };

    const handleCompleteConnectSlack = async (): Promise<void> => {
        // Add your installation logic here
        fetch(App.api_base + '/slack_validate_code', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Vuedoo-Domain': App.domain,
                'X-Vuedoo-Access-Key': data.access_key
            },
            body: JSON.stringify({ code: data.code, redirect_uri: App.base + '/dashboard/?shop=' + data.shop })
        })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json(); // Parse JSON response
        })
        .then((res) => {
            if (res.status === 'success') {
                window.location.assign(App.base + '/dashboard?shop=' + data.shop);
            }
        })
        .catch((error) => {
            console.error('Error:', error);
        });
    };

    const handleFetchConfig = async (): Promise<void> => {
        // Add your installation logic here
        fetch(App.api_base + '/get_config', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Vuedoo-Domain': App.domain,
                'X-Vuedoo-Access-Key': data.access_key
            },
            body: JSON.stringify({})
        })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json(); // Parse JSON response
        })
        .then((res) => {
            if (res.status === 'success') {
                const slack_channel_options: SelectOption[] = [
                    { label: 'Do not notify', value: '' },
                    ...res.slack_channels.map((channel: SlackChannel) => ({
                        label: channel.name,
                        value: channel.id,
                    }))
                ];

                const slack_notifications: Array<Notification> = checkAndSetDefaultNotification( res.notifications );
                setData((prevData) => ({ ...prevData, is_loading: false, slack_name: res.slack_name, slack_channels: res.slack_channels, slack_channel_options: slack_channel_options, slack_notifications: slack_notifications, load_shopify_admin: true }));
            } else {
                setData((prevData) => ({ ...prevData, is_loading: false }));
            }
        })
        .catch((error) => {
            console.error('Error:', error);
        });
    };

    const handleSaveConfig = async ( slack_notifications: any[], timeoutSeconds: number ): Promise<void> => {
        if (timeoutId) {
            clearTimeout(timeoutId);
        }

        const newTimeoutId = setTimeout( async () => {
            fetch(App.api_base + '/save_config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Vuedoo-Domain': App.domain,
                    'X-Vuedoo-Access-Key': data.access_key
                },
                body: JSON.stringify( { slack_notifications: slack_notifications } )
            })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json(); // Parse JSON response
            })
            .then((res) => {
                if (res.status === 'success') {
                    //do nothing
                    //later show something to let user know that it's saved
                }
            })
            .catch((error) => {
                console.error('Error:', error);
            });
        }, timeoutSeconds );
        setTimeoutId( newTimeoutId );
    };

    const checkAndSetDefaultNotification = (slack_notifications: Array<Notification>): Array<Notification> => {
        const n_indices: string[] = ['orderUpdates', 'lowStockAlerts', 'outOfStockAlerts', 'dailySummary'];
        const n_messages: Record<string, string> = { 
            orderUpdates: 'You\'ve received a new order. Check your dashboard for details!', 
            lowStockAlerts: 'Stock is running low for one or more products. Replenish soon to avoid stockouts.', 
            outOfStockAlerts: 'Some products are now out of stock. Take action to restock and notify customers.', 
            dailySummary: 'Here\'s your daily summary: Orders received, stock levels, and sales insights.',
            newCustomerSignup: "A new customer has signed up! Welcome them to your store.",
            cartAbandonment: "A customer has left items in their cart without completing the purchase. Follow up to close the sale.",
            orderShipped: "An order has been shipped. Keep your customer informed with tracking details.",
            refundProcessed: "A refund has been processed for an order. Review the details in your dashboard.",
            positiveReview: "You've received a positive review! Celebrate and thank your customer.",
            negativeFeedback: "A customer has left negative feedback. Review it and take action to address their concerns.",
        };
        n_indices.forEach((index) => {
            // Check if the notification exists
            const notificationExists = slack_notifications.some(
                (notification) => notification.id === index
            );

            // If not, add a default notification
            if (!notificationExists) {
                const defaultNotification: Notification = {
                    id: index,
                    message: n_messages[index], // Customize message for each index
                    channel: { label: 'Do not notify', value: '' }, // Default Slack channel
                };

                // Add the default notification
                slack_notifications.push(defaultNotification);
            }
        });

        return slack_notifications;
    };

    const handleNotificationChange = ( notificationId: string, fieldId: string, value: string ): void => {
        setData((prevData) => {
            const updatedNotifications = prevData.slack_notifications.map((notif) => {
                if (notif.id === notificationId) {
                    if( fieldId == 'message' ) {
                        return {
                            ...notif,
                            [fieldId]: value, // Dynamically update the field
                        };
                    } else if( fieldId == 'channel' ) {
                        let selectedChannel: SlackChannel | undefined = prevData.slack_channels.find(
                            (channel) => channel.id === value
                        );

                        if( selectedChannel == undefined ) {
                            selectedChannel = { name: 'Do not notify', id: '', is_channel: true };
                        }

                        if( selectedChannel != undefined ) {
                            return {
                                ...notif,
                                [fieldId]: { label: selectedChannel.name, value: selectedChannel.id }
                            }
                        }
                    }
                }

                return notif;
            });

            //Update in the database
            handleSaveConfig( updatedNotifications, ( fieldId == 'channel' ? 0 : 1500 ) );

            return { ...prevData, slack_notifications: updatedNotifications };
        });
    };

    const notificationOptionRows = notificationOptions.map((option) => {
         // Find the corresponding notification from the slack_notifications array
        const notification = data.slack_notifications.find(
            (notif) => notif.id === option.id
        );

        // If a matching notification is found, use its message and channel value
        const notification_message = notification ? notification.message : '';
        const channel_value = notification && notification.channel ? notification.channel.value : '';

        return [
            <BlockStack gap="200">
                <Text as="h2" variant="bodyMd" fontWeight="bold">{option.name}</Text>
                <Text as="p" variant="bodyMd">{option.description}</Text>
            </BlockStack>,
            <TextField label="Custom notification message (Optional)" value={notification_message} multiline={1} autoComplete="off"  onChange={(newValue: string) => handleNotificationChange(option.id, "message", newValue)}/>,
            <Select label="Select a channel" options={data.slack_channel_options} value={channel_value} onChange={(newValue: string) => handleNotificationChange(option.id, "channel", newValue)} />
        ]
    });

    return data.is_loading ? (
        <SkeletonPage primaryAction>
            <Layout>
                <Layout.Section>
                    <LegacyCard sectioned>
                        <SkeletonBodyText />
                    </LegacyCard>
                    <LegacyCard sectioned>
                        <TextContainer>
                            <SkeletonDisplayText size="small" />
                            <SkeletonBodyText />
                        </TextContainer>
                    </LegacyCard>
                    <LegacyCard sectioned>
                        <TextContainer>
                            <SkeletonDisplayText size="small" />
                            <SkeletonBodyText />
                        </TextContainer>
                    </LegacyCard>
                </Layout.Section>
            </Layout>
        </SkeletonPage>
    ) : (
        <Page
            title="Manage eComm team on Slack"
            subtitle="Get notification and interact directly from your slack channels"
            compactTitle
        >
            <BlockStack gap="200">
                <Card roundedAbove="sm">
                    <BlockStack gap="200">
                        <InlineGrid columns="1fr auto">
                            <Text as="h2" variant="headingSm">
                                Connect to Slack and Never Miss a Notification!
                            </Text>
                            { data.slack_name != '' ? (
                                <Badge tone="success">Connected</Badge>
                            ) : (
                                <Badge tone="critical">Not connected</Badge>
                            )}
                        </InlineGrid>
                        <BlockStack gap="200">
                            { data.slack_name == '' ? (
                                <Text as="p" variant="bodyMd">
                                    To get started, connect your Slack workspace. This will enable real-time order and stock-out notifications directly in your preferred channels.
                                </Text>
                            ) : (
                                <Text as="p" variant="bodyMd">
                                    Great! You're connected to the Slack workspace: <strong>{ data.slack_name }</strong>. All notifications will be sent there. Need to switch? Use the button below.
                                </Text>
                            )}
                        </BlockStack>

                        <InlineStack align="end">
                            <ButtonGroup>
                                <Button
                                    variant="primary"
                                    onClick={handleInitSlack}
                                    accessibilityLabel="Connect/change your slack"
                                    icon={ConnectIcon}
                                >
                                { data.slack_name == '' ? 'Connect to Slack' : 'Change Slack Workspace' }
                                </Button>
                            </ButtonGroup>
                        </InlineStack>
                    </BlockStack>
                </Card>

                <Card roundedAbove="sm">
                    <BlockStack gap="200">
                        <InlineGrid columns="1fr auto">
                            <Text as="h2" variant="headingSm">
                                Customize Your Notifications for Seamless Management
                            </Text>
                        </InlineGrid>
                        { data.slack_name == '' ? (
                            <Text as="p" variant="bodyMd">
                                Connect your Slack workspace above to configure your notifications.
                            </Text>
                        ) : (
                            <BlockStack gap="200">
                                <Text as="h2" variant="bodyMd">
                                    Select the notifications you'd like to receive in Slack. Stay on top of orders and stock status effortlessly!
                                </Text>
                                
                                <DataTable
                                    columnContentTypes={['text', 'text', 'text']}
                                    headings={['Notification', 'Description', 'Action']}
                                    rows={notificationOptionRows}
                                />
                            </BlockStack>
                        )}
                    </BlockStack>
                </Card>
            </BlockStack>
        </Page>
    );
};

export default Dashboard;