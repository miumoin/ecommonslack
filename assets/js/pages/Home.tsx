import { SkeletonPage, Layout, Form, FormLayout, LegacyCard, SkeletonBodyText, TextContainer, SkeletonDisplayText, Page, Box, Card, Text, TextField, Button, InlineStack } from '@shopify/polaris';
import React, {useState, useEffect} from 'react';
import { useNavigate } from 'react-router-dom';
import Cookies from 'js-cookie';

// Define types for the data state
interface DataState {
    is_loading: boolean;
    shop: string;
    code: string;
    hmac: string;
    timestamp: string;
    host: string;
    access_key: string;
    is_installed: number;
};

const Home: React.FC = () => {
    const [data, setData] = useState<DataState>({
        is_loading: true,
        shop: '',
        code: '',
        hmac: '',
        timestamp: '',
        host: '',
        access_key: '',
        is_installed: 0
    });

    const navigate = useNavigate();

    /*
    ** Initiate variables from url parameters and local storage
    */
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search);
        const shop = urlParams.get('shop') || '';
        const is_loading = ( shop != '' ? true : false );
        const code = urlParams.get('code') || '';
        const hmac = urlParams.get('hmac') || '';
        const timestamp = urlParams.get('timestamp') || '';
        const host = urlParams.get('host') || '';
        const is_installed = parseInt(urlParams.get('is_installed') || '0', 10);

        // Retrieve access_key from localStorage or similar storage
        const access_key: string = shop ? Cookies.get(`access_key_${shop}`) || '' : '';

        // Set state with extracted values
        setData({
            is_loading : is_loading,
            shop : shop,
            code : code,
            hmac : hmac,
            timestamp : timestamp,
            host : host,
            access_key : access_key,
            is_installed : is_installed
        });
    }, []);

    /*
    ** Get access token if code and hmac parameters exists in the url
    */
    useEffect(() => {
        const urlParams = new URLSearchParams(window.location.search);

        if ( urlParams.get('code') != '' &&  urlParams.get('code') != null ) {
            getAccessToken();
        } else if( urlParams.get('shop') != null && urlParams.get('shop') != '' && urlParams.get('code') == null ) {
            getShop();
        }
    }, [data]);

    const getShop = () => {
        handleInstallApp();
    };

    const handleInstallApp = async (): Promise<void> => {
        // Add your installation logic here
        fetch(App.api_base + '/get_installation_url', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Vuedoo-Domain': App.domain,
                'X-Vuedoo-Access-Key': ''
            },
            body: JSON.stringify({ shop: data.shop })
        })
        .then((response) => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json(); // Parse JSON response
        })
        .then((res) => {
            if (res.status === 'success') {
                if( window.top != null ) window.top.location.href = res.installation_url + '&redirect_uri=' + App.base;
                else window.location.href = res.installation_url + '&redirect_uri=' + App.base;
            }
        })
        .catch((error) => {
            console.error('Error:', error);
        });
    };

    const getAccessToken = async (): Promise<void> => {
        fetch( App.api_base + '/get_access_token', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Vuedoo-Domain': App.domain,
                'X-Vuedoo-Access-Key': '',
            },
            body: JSON.stringify({
                shop: data.shop,
                code: data.code,
                hmac: data.hmac,
                timestamp: data.timestamp,
                host: data.host,
            }),
        })
        .then((response) => response.json()) // Parse the response as JSON
        .then((data) => {
            if (data.status === 'success') {
                localStorage.setItem(`access_key_${data.shop}`, data.access_key);
                
                Cookies.set(`access_key_${data.shop}`, data.access_key, {
                    secure: true,          // Only over HTTPS
                    sameSite: 'None',      // Supports cross-origin iframe contexts
                    path: '/',             // Available site-wide
                });

                navigate(`/dashboard?shop=${encodeURIComponent(data.shop)}`);
            }
        })
        .catch((error) => {
            console.error(error);
        });
    };

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
        <Page title="Install Store Blog on your Shopify store">
            <Box>
                <Form onSubmit={handleInstallApp}>
                    <FormLayout>
                        <Card>
                            <Text variant="headingSm" as="h2">
                                Your shop URL
                            </Text>
                            <Box>
                                <TextField
                                    label="" // Add a label for accessibility
                                    placeholder="i.e. your-shop.myshopify.com"
                                    value={data.shop}
                                    onChange={(value) => setData((prevData) => ({ ...prevData, shop: value }))}
                                    helpText="Input your Shopify store subdomain URL to install the app. Example: shop-url.myshopify.com"
                                    autoComplete="off" // Optional, depending on your needs
                                />
                            </Box>
                            <InlineStack align="end">
                                <Button onClick={handleInstallApp}>
                                    Install
                                </Button>
                            </InlineStack>
                        </Card>
                    </FormLayout>
                </Form>
            </Box>
        </Page>
    );
};

export default Home;